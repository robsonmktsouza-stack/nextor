#!/usr/bin/env bash
# CI-only destructive actions against disposable fixtures; never the VPS.
set -Eeuo pipefail
umask 077
REPO="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
ROOT="$(mktemp -d)"
trap 'sudo rm -rf --one-file-system "$ROOT"' EXIT
mkdir -p "$ROOT/scripts" "$ROOT/code/releases" "$ROOT/instances" "$ROOT/code"
# Test-only copy of the script whose global root is replaced with a disposable path.
sed "s@^LM_ROOT=/srv/lumeron$@LM_ROOT=$ROOT@" \
  "$REPO/deploy/instances/lib.sh" > "$ROOT/scripts/lib.sh"
cp "$REPO/deploy/instances/prune.sh" "$ROOT/scripts/prune.sh"
A=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
B=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
C=cccccccccccccccccccccccccccccccccccccccc
for sha in "$A" "$B" "$C"; do
  mkdir -p "$ROOT/code/releases/$sha"
  echo "$sha" > "$ROOT/code/releases/$sha/.lumeron-code-sha"
  touch -d '90 days ago' "$ROOT/code/releases/$sha"
done
echo "$A" > "$ROOT/code/approved-default"
mkdir -p "$ROOT/instances/c_1/releases" "$ROOT/instances/c_2/releases"
for id in c_1 c_2; do
  mkdir -p "$ROOT/instances/$id/shared/storage/app/private" \
    "$ROOT/instances/$id/shared/storage/logs" "$ROOT/instances/$id/uploads"
  printf 'APP_KEY=unique_%s\n' "$id" > "$ROOT/instances/$id/shared/.env"
  echo 'do-not-delete-xml' > "$ROOT/instances/$id/shared/storage/app/private/authorized.xml"
  echo 'do-not-delete-a1' > "$ROOT/instances/$id/shared/storage/app/private/certificate.pfx"
  echo 'do-not-delete-upload' > "$ROOT/instances/$id/uploads/photo.bin"
  mkdir -p "$ROOT/instances/$id/releases/r1"
  echo "$A" > "$ROOT/instances/$id/releases/r1/.lumeron-instance-code"
  ln -s releases/r1 "$ROOT/instances/$id/current"
done
mkdir -p "$ROOT/instances/c_1/releases/rold" "$ROOT/instances/c_1/releases/rorphan"
echo "$B" > "$ROOT/instances/c_1/releases/rold/.lumeron-instance-code"
echo "$C" > "$ROOT/instances/c_1/releases/rorphan/.lumeron-instance-code"
ln -s releases/rold "$ROOT/instances/c_1/previous"
touch -d '90 days ago' "$ROOT/instances/c_1/releases/rold" \
  "$ROOT/instances/c_1/releases/rorphan" \
  "$ROOT/instances/c_1/releases/r1" "$ROOT/instances/c_2/releases/r1"
sudo chown root:root "$ROOT/code/releases/$A" "$ROOT/code/releases/$B" "$ROOT/code/releases/$C"
bash "$ROOT/scripts/prune.sh" > "$ROOT/dry-run"
[[ -d "$ROOT/code/releases/$C" && -d "$ROOT/instances/c_1/releases/rorphan" ]]
sudo bash "$ROOT/scripts/prune.sh" --apply > "$ROOT/applied"
[[ ! -e "$ROOT/code/releases/$C" && ! -e "$ROOT/instances/c_1/releases/rorphan" ]]
[[ -d "$ROOT/code/releases/$A" && -d "$ROOT/code/releases/$B" ]]
[[ -d "$ROOT/instances/c_1/releases/r1" && -d "$ROOT/instances/c_2/releases/r1" ]]
[[ -d "$ROOT/instances/c_1/releases/rold" ]]
for id in c_1 c_2; do
  [[ -f "$ROOT/instances/$id/shared/.env" ]]
  [[ -f "$ROOT/instances/$id/shared/storage/app/private/authorized.xml" ]]
  [[ -f "$ROOT/instances/$id/shared/storage/app/private/certificate.pfx" ]]
  [[ -f "$ROOT/instances/$id/uploads/photo.bin" ]]
done
echo '[OK] Prune conserva versões utilizadas, rollback, APP_KEY, XML, certificado e uploads.'
