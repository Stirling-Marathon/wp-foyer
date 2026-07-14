#!/usr/bin/env sh
set -eu

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
OUTPUT="dist/wordpress-foyer-roku.zip"
CONFIG_PATH=""
API_BASE_URL="${WORDPRESS_FOYER_API_BASE_URL:-}"

while [ "$#" -gt 0 ]; do
  case "$1" in
    --config)
      CONFIG_PATH="$2"
      shift 2
      ;;
    --api-base-url)
      API_BASE_URL="$2"
      shift 2
      ;;
    --output)
      OUTPUT="$2"
      shift 2
      ;;
    *)
      OUTPUT="$1"
      shift
      ;;
  esac
done

if [ -z "$API_BASE_URL" ]; then
  if [ -n "$CONFIG_PATH" ]; then
    CONFIG_FILE="$CONFIG_PATH"
  else
    CONFIG_FILE="$ROOT/config.local.json"
  fi
  if [ ! -f "$CONFIG_FILE" ]; then
    echo "Missing Roku API configuration. Create roku-app/config.local.json or pass --config / --api-base-url." >&2
    exit 1
  fi
  API_BASE_URL="$(php -r '
    $path = $argv[1];
    $json = json_decode(file_get_contents($path), true);
    if (!is_array($json)) { fwrite(STDERR, "Invalid JSON in Roku API configuration\n"); exit(2); }
    if (!array_key_exists("apiBaseUrl", $json)) { fwrite(STDERR, "Roku API configuration must contain apiBaseUrl\n"); exit(3); }
    if (!is_string($json["apiBaseUrl"])) { fwrite(STDERR, "apiBaseUrl must be a string\n"); exit(4); }
    echo $json["apiBaseUrl"];
  ' "$CONFIG_FILE")"
fi

API_BASE_URL="$(php -r '
  $url = trim($argv[1]);
  $url = rtrim($url, "/");
  if ($url === "") { fwrite(STDERR, "apiBaseUrl must not be empty\n"); exit(2); }
  if (!preg_match("#^https?://#i", $url)) { fwrite(STDERR, "apiBaseUrl must begin with http:// or https://\n"); exit(3); }
  if (preg_match("#[\"'\''[:space:]]#", $url)) { fwrite(STDERR, "apiBaseUrl must not contain quotes or whitespace\n"); exit(6); }
  $parts = parse_url($url);
  if (!$parts || isset($parts["user"]) || isset($parts["pass"])) { fwrite(STDERR, "apiBaseUrl must not contain credentials\n"); exit(4); }
  $path = isset($parts["path"]) ? $parts["path"] : "";
  if (preg_match("#/displays(/.*)?$#", $path)) { fwrite(STDERR, "apiBaseUrl must be the REST namespace root, not a displays endpoint\n"); exit(5); }
  echo $url;
' "$API_BASE_URL")"

if [ ! -f "$ROOT/manifest" ]; then
  echo "Missing Roku manifest" >&2
  exit 1
fi

for required in \
  source/main.brs \
  components/MainScene.xml \
  components/MainScene.brs \
  components/SignagePlayer.xml \
  components/SignagePlayer.brs \
  components/ManifestTask.xml \
  components/CacheManifestTask.xml \
  components/ImageDownloadTask.xml
do
  if [ ! -f "$ROOT/$required" ]; then
    echo "Missing required Roku runtime file: $required" >&2
    exit 1
  fi
done

DIST="$ROOT/dist"
BUILD_ROOT="$ROOT/.build"
STAGING="$BUILD_ROOT/package-$$"
ZIP_PATH="$ROOT/$OUTPUT"
mkdir -p "$DIST" "$BUILD_ROOT"
rm -f "$ZIP_PATH"
rm -rf "$STAGING"

cleanup() {
  rm -rf "$STAGING"
}
trap cleanup EXIT

mkdir -p "$STAGING"
cp "$ROOT/manifest" "$STAGING/manifest"
cp -R "$ROOT/source" "$STAGING/source"
cp -R "$ROOT/components" "$STAGING/components"
cp -R "$ROOT/images" "$STAGING/images"

php -r '
  $url = $argv[1];
  $escaped = str_replace(["\\", "\""], ["\\\\", "\"\""], $url);
  file_put_contents($argv[2], "function GetConfiguredApiBaseUrl() as String\n    return \"" . $escaped . "\"\nend function\n");
' "$API_BASE_URL" "$STAGING/source/AppConfig.generated.brs"

cd "$STAGING"
zip -X -q -r "$ZIP_PATH" manifest source components images

echo "Created $ZIP_PATH"
