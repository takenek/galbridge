#!/usr/bin/env bash
#
# GAL Bridge - verify a release tag (the maintainer before pushing it; the CI job "release").
#
#   tests/release/verify-tag.sh vX.Y.Z [--commit <rev>] [--require-main] [--signers <file>]
#
# The tag is accepted (exit 0; git's "Good ..." line is printed) only when ALL of these hold:
#   - its name is vMAJOR.MINOR.PATCH;
#   - refs/tags/<name> is an annotated tag object whose own header says "type commit" and
#     "tag <name>" (a signed tag object of another version, pushed under this name, is refused);
#   - it points to <rev> when --commit is given (CI: the commit it checked out and tested);
#   - it carries an SSH signature (git 2.34 or later) that a key of the allowed-signers file made
#     for the principal galbridge-release in the namespace git - GPG signatures are refused;
#   - with --require-main: its commit is part of refs/remotes/origin/main, which must exist.
#     Nothing is fetched: CI checks out the whole history, and a fetch of its own would need
#     credentials on a private repository.
# --signers: the allowed-signers file (default: .github/release-signers of this working tree; CI
# passes the copy of main). Exit 1 = refused (the reason on standard error), 2 = usage.
#
# @license GPL-3.0-or-later
set -euo pipefail

PRINCIPAL=galbridge-release
die() { echo "verify-tag.sh: $*" >&2; exit 1; }
usage() { echo "usage: $0 vX.Y.Z [--commit <rev>] [--require-main] [--signers <file>]" >&2; exit 2; }

TAG=''; WANT=''; REQUIRE_MAIN=0; SIGNERS=''
while [ $# -gt 0 ]; do
  case "$1" in
    --commit) [ $# -ge 2 ] || usage; WANT="$2"; shift 2 ;;
    --require-main) REQUIRE_MAIN=1; shift ;;
    --signers) [ $# -ge 2 ] || usage; SIGNERS="$2"; shift 2 ;;
    -*) usage ;;
    *) [ -z "$TAG" ] || usage; TAG="$1"; shift ;;
  esac
done
[ -n "$TAG" ] || usage

[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "'$TAG' is not a release tag name (vMAJOR.MINOR.PATCH)"
top="$(git rev-parse --show-toplevel)" || die "not inside a git working tree"
[ -n "$SIGNERS" ] || SIGNERS="$top/.github/release-signers"
[ -f "$SIGNERS" ] || die "allowed-signers file $SIGNERS not found"
grep -q "^$PRINCIPAL " "$SIGNERS" || die "$SIGNERS lists no key for the principal $PRINCIPAL"

ref="refs/tags/$TAG"
type="$(git cat-file -t "$ref" 2>/dev/null)" || die "there is no tag $TAG"
[ "$type" = tag ] || die "$TAG is a lightweight tag (a reference to a $type): a release is a signed, annotated tag"
object="$(git cat-file tag "$ref")"
header="${object%%$'\n\n'*}"
grep -qxF 'type commit' <<< "$header" || die "the tag object $TAG does not point to a commit"
grep -qxF "tag $TAG" <<< "$header" || die "the tag object stored as $TAG was made for another name ($(grep -m1 '^tag ' <<< "$header" || echo 'none'))"
grep -qxF -- '-----BEGIN SSH SIGNATURE-----' <<< "$object" || die "$TAG carries no SSH signature (unsigned, or signed with GPG): docs/RELEASING.md step 6"

commit="$(git rev-parse --verify -q "$ref^{commit}")" || die "the commit of $TAG is not in this repository"
if [ -n "$WANT" ]; then
  want="$(git rev-parse --verify -q "$WANT^{commit}")" || die "--commit $WANT is not a commit of this repository"
  [ "$commit" = "$want" ] || die "$TAG points to $commit, not to $want"
fi

# git hands the signature to ssh-keygen -Y verify with the tagger date as the verification time, so
# a key line with valid-before="YYYYMMDD" still accepts the tags that were signed before that day.
if ! verdict="$(git -c gpg.ssh.program=ssh-keygen -c gpg.ssh.allowedSignersFile="$SIGNERS" verify-tag --raw "$ref" 2>&1 >/dev/null)"; then
  die "the signature of $TAG was not made by a key of $SIGNERS: $(tr '\n' ' ' <<< "$verdict")"
fi
good="$(grep -m1 -E "^Good \"git\" signature for $PRINCIPAL with [A-Z0-9-]+ key SHA256:[A-Za-z0-9+/]{43}$" <<< "$verdict")" \
  || die "unexpected answer of git verify-tag: $(tr '\n' ' ' <<< "$verdict")"

if [ "$REQUIRE_MAIN" = 1 ]; then
  main="$(git rev-parse --verify -q refs/remotes/origin/main)" || die "refs/remotes/origin/main is missing (nothing is fetched: check out the whole history first)"
  git merge-base --is-ancestor "$commit" "$main" || die "the commit of $TAG ($commit) is not part of main"
fi

echo "$good"
echo "$TAG -> commit $commit"
