#!/usr/bin/env bash
# Publishes a GitHub release for every vX.Y.Z tag that has none yet (oldest first), so missing
# releases are filled in by the next run. Notes: the rows of the README "Releases" table (as of the
# tag) newer than the previous tag, or only the tag's own row for the first tag; falls back to the
# commit subjects. Needs GH_TOKEN, GITHUB_REPOSITORY and all tags.
set -euo pipefail

repo="${GITHUB_REPOSITORY:?}"
existing=$(gh release list --repo "$repo" --limit 1000 --json tagName --jq '.[].tagName')

# Prints "- **X.Y.Z** (Art): Änderung" for README rows with prev < version <= current.
readme_rows() {
  local tag="$1" prev="$2"
  git show "$tag:README.md" 2>/dev/null | awk -F'|' '
    /^## Releases/ { on = 1; next }
    on && /^## / { on = 0 }
    on && $2 ~ /^ *[0-9]+\.[0-9]+\.[0-9]+ *$/ {
      v = $2; gsub(/ /, "", v); a = $3; gsub(/^ +| +$/, "", a); c = $4; gsub(/^ +| +$/, "", c)
      print v "\t" a "\t" c
    }' | while IFS=$'\t' read -r v art change; do
    if [ "$(printf '%s\n%s\n' "$v" "${tag#v}" | sort -V | tail -n1)" != "${tag#v}" ]; then continue; fi
    if [ -n "$prev" ]; then
      [ "$v" = "${prev#v}" ] && continue
      [ "$(printf '%s\n%s\n' "$v" "${prev#v}" | sort -V | tail -n1)" = "${prev#v}" ] && continue
    elif [ "$v" != "${tag#v}" ]; then
      continue
    fi
    echo "- **$v** ($art): $change"
  done
}

prev=""
for tag in $(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --sort=v:refname | grep -E '^v[0-9]+\.[0-9]+\.[0-9]+$'); do
  if grep -qxF "$tag" <<<"$existing"; then
    prev="$tag"; continue
  fi
  notes=$(mktemp)
  rows=$(readme_rows "$tag" "$prev")
  {
    echo "## Änderungen"
    echo
    if [ -n "$rows" ]; then
      echo "$rows"
    elif [ -n "$prev" ]; then
      git log --no-merges --format='- %s' "$prev..$tag" | grep -v '^- Bump version to ' | head -n 200 || true
    else
      echo "- $tag"
    fi
  } > "$notes"
  # Two workflows can run this at the same time: a release created meanwhile is not an error.
  if gh release create "$tag" --repo "$repo" --verify-tag --title "$tag" --notes-file "$notes"; then
    echo "Published release $tag"
  else
    gh release view "$tag" --repo "$repo" >/dev/null
    echo "Release $tag exists already"
  fi
  rm -f "$notes"
  prev="$tag"
done
