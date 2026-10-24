<?php

namespace App\Services;

use App\Models\LegalRevision;
use App\Support\LegalTexts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps the archive of legal text revisions (LegalRevision) in sync with the current texts:
 * a new revision is stored whenever the rendered text (sha256) or the "Stand" of a kind differs
 * from its newest revision. Called after the backend saves (explicit reason) and lazily where the
 * texts are shown or accepted (reason "auto"), so deploys of new default texts or changed
 * placeholder values are archived as well.
 *
 * Per request (scoped binding) the newest committed revision per kind is cached: an unchanged text
 * costs one SELECT per kind and request, further calls only a hash. Creation runs in a transaction
 * that first locks the audit chain head (the audit entry needs that lock anyway), so concurrent
 * requests never store the same revision twice.
 */
class LegalArchive
{
    /** @var array<string, LegalRevision> */
    private array $latest = [];

    /** Revision of the current text of a kind (created when missing). */
    public static function current(string $kind, string $reason = LegalRevision::REASON_AUTO): LegalRevision
    {
        return app(self::class)->ensure($kind, $reason);
    }

    /**
     * For display only (public pages): the current revision, or an unsaved one with the current text
     * when the archive cannot be written (e.g. migration pending). Never breaks the page.
     */
    public static function forDisplay(string $kind): LegalRevision
    {
        try {
            return self::current($kind);
        } catch (Throwable $e) {
            report($e);
            $body = LegalTexts::source($kind);

            return new LegalRevision(['kind' => $kind, 'version' => LegalTexts::version($kind), 'body' => $body, 'hash' => hash('sha256', $body)]);
        }
    }

    /**
     * Current revisions of both kinds.
     *
     * @return array<string, LegalRevision>
     */
    public static function currentAll(string $reason = LegalRevision::REASON_AUTO): array
    {
        $revisions = [];
        foreach (LegalTexts::KINDS as $kind) {
            $revisions[$kind] = self::current($kind, $reason);
        }

        return $revisions;
    }

    public function ensure(string $kind, string $reason = LegalRevision::REASON_AUTO): LegalRevision
    {
        $body = LegalTexts::source($kind);
        $hash = hash('sha256', $body);
        $version = LegalTexts::version($kind);

        $cached = $this->latest[$kind] ?? null;
        if ($cached !== null && $cached->matches($hash, $version)) {
            return $cached;
        }
        $latest = LegalRevision::latestOf($kind);
        if ($latest !== null && $latest->matches($hash, $version)) {
            return $this->latest[$kind] = $latest;
        }

        return DB::transaction(function () use ($kind, $reason, $body, $hash, $version) {
            // Serialises writers (same lock Audit::log() takes below), then re-check under the lock.
            DB::table('audit_chain')->where('id', 1)->lockForUpdate()->first();
            $latest = LegalRevision::latestOf($kind, true);
            if ($latest !== null && $latest->matches($hash, $version)) {
                DB::afterCommit(fn () => $this->latest[$kind] = $latest);

                return $latest;
            }

            $revision = LegalRevision::query()->create([
                'kind' => $kind,
                'version' => $version,
                'body' => $body,
                'hash' => $hash,
                'reason' => $latest === null ? LegalRevision::REASON_INITIAL : $reason,
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]);
            // Not the body: it is stored in the revision itself.
            Audit::log('legal.revision', $revision, [], [
                'kind' => $kind, 'version' => $version, 'hash' => $hash, 'reason' => $revision->reason,
            ]);
            // Cached only once committed: a rolled back outer transaction must not leave a ghost revision.
            DB::afterCommit(fn () => $this->latest[$kind] = $revision);

            return $revision;
        });
    }
}
