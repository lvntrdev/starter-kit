#!/usr/bin/env bash
#
# Starter Kit yayın scripti — sürümü seçer, kalite kapısını çalıştırır, etiketi
# oluşturur ve remote'a gönderir.
#
# Kullanım: ./release.sh [--skip-checks] [--allow-branch]
#   --skip-checks   Kalite kapısının TAMAMINI atlar (yerel kontroller + uzak CI
#                   doğrulaması + changelog kontrolü). Bilinçli ve tek kaçamak.
#   --allow-branch  'main' dışındaki bir branch'ten yayına izin verir.
#
# Gereksinimler (kapı --skip-checks olmadan çalıştığında):
#   - php + composer      → composer lint / test / analyse / security
#   - node + npm          → stubs frontend kapısı (ci · build · typecheck ·
#                           lint:ci · test); Wayfinder route stub'ları PHP
#                           olmadan scripts/ci/generate-route-stubs.mjs ile üretilir
#   - gh (GitHub CLI) + AĞ ERİŞİMİ + 'gh auth login'
#                         → etiketlenecek TAM commit'in GitHub Actions sonucu
#                           doğrulanır. gh kurulu değilse ya da oturum açılmamışsa
#                           yayın DURUR; sessizce atlanmaz (bkz. verify_remote_ci).
#
# Yayın akışı: commit → 'git push origin main' → CI yeşillenene kadar bekle →
# ./release.sh. Uzak CI kapısı, koşusu olmayan bir commit'in etiketlenmesini
# engeller.
set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
GRAY='\033[0;90m'
BOLD='\033[1m'
NC='\033[0m'

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

SKIP_CHECKS=0
ALLOW_BRANCH=0
for arg in "$@"; do
    case "${arg}" in
        --skip-checks)  SKIP_CHECKS=1 ;;
        --allow-branch) ALLOW_BRANCH=1 ;;
    esac
done

info()   { echo -e "  ${GREEN}INFO${NC}    ${1}"; }
error()  { echo -e "  ${RED}ERROR${NC}   ${1}"; exit 1; }
warn()   { echo -e "  ${YELLOW}WARN${NC}    ${1}"; }
detail() { printf "  %-32s %b\n" "${1}" "${2}"; }

[[ ! -d "${DIR}/.git" ]] && error "Bu script yalnızca paket kaynak dizininden çalıştırılabilir."

git -C "${DIR}" remote | grep -q . || error "Git remote yapılandırılmamış.\n  Çalıştır: git remote add origin <repo-url>"

if [[ -n "$(git -C "${DIR}" status --porcelain)" ]]; then
    echo -e "\n  ${RED}ERROR${NC}   Commit edilmemiş değişiklikler var:\n"
    git -C "${DIR}" status --short | sed 's/^/    /'
    echo -e "\n  Önce değişiklikleri commit et, sonra release yap.\n"
    exit 1
fi

CURRENT_BRANCH=$(git -C "${DIR}" rev-parse --abbrev-ref HEAD)
if [[ "${CURRENT_BRANCH}" != "main" && "${ALLOW_BRANCH}" -ne 1 ]]; then
    error "Yayın yalnızca 'main' branch'ten yapılır (mevcut: ${CURRENT_BRANCH}).\n  Bilerek başka branch'ten yayınlıyorsan --allow-branch ile tekrar dene."
fi

echo ""
echo -e "  ${BOLD}Starter Kit Yayın${NC}"
echo ""

CURRENT_TAG=$(git -C "${DIR}" describe --tags --abbrev=0 2>/dev/null || echo "")
if [[ -n "${CURRENT_TAG}" ]]; then
    detail "Mevcut versiyon" "${CYAN}${CURRENT_TAG}${NC}"
else
    detail "Mevcut versiyon" "${YELLOW}henüz etiket yok${NC}"
fi

parse_version() {
    local ver="${1:-0.0.0}"
    ver="${ver#v}"
    echo "$(echo "${ver}" | cut -d. -f1) $(echo "${ver}" | cut -d. -f2) $(echo "${ver}" | cut -d. -f3)"
}

bump_version() {
    local type="${1}"
    local major minor patch
    read -r major minor patch <<< "$(parse_version "${CURRENT_TAG:-0.0.0}")"
    major="${major:-0}"; minor="${minor:-0}"; patch="${patch:-0}"
    case "${type}" in
        major) echo "$((major + 1)).0.0" ;;
        minor) echo "${major}.$((minor + 1)).0" ;;
        patch) echo "${major}.${minor}.$((patch + 1))" ;;
    esac
}

PATCH_VER=$(bump_version patch)
MINOR_VER=$(bump_version minor)
MAJOR_VER=$(bump_version major)

echo ""
echo "  Versiyon artırma tipi:"
echo "  1) Patch (hata düzeltme)     → ${PATCH_VER}"
echo "  2) Minor (yeni özellik)      → ${MINOR_VER}"
echo "  3) Major (kıran değişiklik)  → ${MAJOR_VER}"
echo "  4) Özel versiyon"
echo ""
read -rp "  Seçim [1-4, varsayılan 1]: " CHOICE
CHOICE="${CHOICE:-1}"

case "${CHOICE}" in
    1) VERSION="${PATCH_VER}" ;;
    2) VERSION="${MINOR_VER}" ;;
    3) VERSION="${MAJOR_VER}" ;;
    4)
        read -rp "  Versiyon (örn. 1.0.0): " VERSION
        [[ -z "${VERSION}" ]] && error "Versiyon boş olamaz."
        ;;
    *) error "Geçersiz seçim." ;;
esac

[[ "${VERSION}" != v* ]] && VERSION="v${VERSION}"
CLEAN_VERSION="${VERSION#v}"

if git -C "${DIR}" tag -l "${VERSION}" | grep -q "^${VERSION}$"; then
    error "${VERSION} etiketi zaten mevcut."
fi

REMOTE=$(git -C "${DIR}" remote get-url origin 2>/dev/null || echo "bilinmiyor")

echo ""
detail "Yeni versiyon" "${GREEN}${VERSION}${NC}"
detail "Remote"        "${REMOTE}"
detail "Branch"        "${CURRENT_BRANCH}"
echo ""

read -rp "  ${VERSION} yayınlansın mı? [E/h]: " CONFIRM
CONFIRM="${CONFIRM:-E}"
[[ "${CONFIRM}" =~ ^[EeYy]$ ]] || { warn "Yayın iptal edildi."; exit 0; }

# Sürüm başlığının 3 changelog dosyasında da bulunduğunu doğrular.
# Desenler CI'daki "changelog-sync" adımıyla birebir aynıdır (bkz. .github/workflows/ci.yml)
# — yerelde CI'dan önce hata verir. Eksikse DUR (mevcut tek-dosya "e" kaçamağı kaldırıldı).
verify_changelogs() {
    local ver="${1}"
    local escaped="${ver//./\\.}"
    local missing=()

    grep -qE "^## \[${escaped}\]" "${DIR}/CHANGELOG.md" 2>/dev/null \
        || missing+=("CHANGELOG.md")

    local f
    for f in docs/CHANGELOG.md docs/CHANGELOG.tr.md; do
        grep -qE "^## .* — v${escaped}\$" "${DIR}/${f}" 2>/dev/null \
            || missing+=("${f}")
    done

    if [[ ${#missing[@]} -gt 0 ]]; then
        error "v${ver} sürüm başlığı şu changelog dosyalarında eksik:\n$(printf '    - %s\n' "${missing[@]}")\n  Üç dosyaya da (root + docs EN + docs TR) girişi ekle, sonra tekrar dene (ya da --skip-checks ile atla)."
    fi
    detail "Changelog kontrolü" "${GREEN}GEÇTİ${NC} ${GRAY}(3 dosya)${NC}"
}

# Dağıtım arşivine (git archive HEAD, .gitattributes export-ignore uygulanmış hâli)
# yalnızca geliştirmeye özel yolların sızıp sızmadığını denetler; bulursa UYARIR (durdurmaz).
smoke_test_dist() {
    local unexpected
    unexpected=$(git -C "${DIR}" archive HEAD 2>/dev/null | tar -t 2>/dev/null \
        | grep -vE '/$' \
        | grep -E '^(\.ai/|\.github/|\.claude/|release\.sh$|scripts/|plan-docs/|package-audit-notes/|tests/|phpunit\.xml$|testbench\.yaml$|pint\.json$|CHANGELOG\.md$|README-tr\.md$|\.gitignore$|\.gitattributes$|\.npmignore$)' \
        || true)
    if [[ -n "${unexpected}" ]]; then
        warn "Dağıtım arşivinde beklenmeyen (geliştirmeye özel) yollar var:"
        echo "${unexpected}" | sed 's/^/      /'
        warn ".gitattributes içinde 'export-ignore' ile hariç tut — aksi hâlde yayınlanan pakete sızar."
    else
        detail "Dist smoke testi" "${GREEN}TEMİZ${NC}"
    fi
}

# Frontend kapısı — CI'daki "node" job'ının adım SIRASINI birebir izler
# (bkz. .github/workflows/ci.yml). Sıra rastgele değil: `vite build`,
# `auto-imports.d.ts` / `components.d.ts` dosyalarını üretir ve `vue-tsc`
# bunlara bağımlıdır; bu yüzden build, typecheck'ten ÖNCE koşar.
# Üretilen her şey (.gitignore'lu): stubs/node_modules, stubs/vendor symlink'i,
# stubs/resources/js/routes, build çıktıları — çalışma ağacını kirletmez.
run_frontend_gate() {
    local stubs="${DIR}/stubs"

    npm --prefix "${stubs}" ci \
        || error "npm ci (stubs) başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."

    # @lvntr/* alias'ı paket kökünü gösteren symlink ile çözülür (CI ile aynı).
    if [[ ! -e "${stubs}/vendor/lvntr/laravel-starter-kit" ]]; then
        mkdir -p "${stubs}/vendor/lvntr"
        ln -s "${DIR}" "${stubs}/vendor/lvntr/laravel-starter-kit" \
            || error "stubs/vendor/lvntr symlink'i oluşturulamadı."
    fi

    # Bu depoda artisan yok; Wayfinder route'ları CI fallback'iyle üretilir.
    node "${DIR}/scripts/ci/generate-route-stubs.mjs" \
        || error "Wayfinder route stub üretimi başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."

    npm --prefix "${stubs}" run build \
        || error "npm run build (stubs) başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."
    npm --prefix "${stubs}" run typecheck \
        || error "npm run typecheck (stubs) başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."
    npm --prefix "${stubs}" run lint:ci \
        || error "npm run lint:ci (stubs) başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."
    npm --prefix "${stubs}" run test \
        || error "npm run test (stubs) başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."

    detail "Frontend kapısı" "${GREEN}GEÇTİ${NC} ${GRAY}(build · typecheck · lint · test)${NC}"
}

# Etiketlenecek TAM commit'in (HEAD) uzak CI sonucunu doğrular.
# Her workflow için YALNIZCA en son koşu değerlendirilir (databaseId'si en büyük
# olan): yeniden çalıştırılmış ya da concurrency ile iptal edilmiş eski koşular
# yanlış kırmızı üretmesin diye. `skipped` sonucu geçerli sayılır (koşul gereği
# atlanan workflow), bunun dışında success olmayan her şey yayını durdurur.
#
# gh yoksa ya da oturum açılmamışsa DURUR — sessiz atlama, bu kapının kapatmak
# için var olduğu hatanın ta kendisi. Tek bilinçli kaçamak: --skip-checks.
verify_remote_ci() {
    local sha rows name status conclusion
    local failed=0 pending=0

    sha=$(git -C "${DIR}" rev-parse HEAD)

    if ! command -v gh >/dev/null 2>&1; then
        error "GitHub CLI (gh) bulunamadı — etiketlenecek commit'in CI sonucu doğrulanamıyor.\n  Kur: https://cli.github.com  (bilinçli atlamak için --skip-checks)."
    fi

    if ! gh auth status >/dev/null 2>&1; then
        error "GitHub CLI kimlik doğrulaması yok — 'gh auth login' çalıştır.\n  (bilinçli atlamak için --skip-checks)."
    fi

    rows=$(cd "${DIR}" && gh run list --commit "${sha}" --limit 100 \
        --json workflowName,status,conclusion,databaseId \
        --jq 'group_by(.workflowName) | map(max_by(.databaseId)) | .[] | "\(.workflowName)\t\(.status)\t\(.conclusion)"') \
        || error "Uzak CI sorgusu başarısız (gh run list). Ağ/erişim sorununu çöz ve tekrar dene (ya da --skip-checks ile atla)."

    if [[ -z "${rows}" ]]; then
        error "${sha:0:12} commit'i için uzak CI koşusu yok.\n  Bu commit remote'a gönderilmemiş olabilir: 'git push origin ${CURRENT_BRANCH}' çalıştır, CI yeşillenince tekrar dene (ya da --skip-checks ile atla)."
    fi

    while IFS=$'\t' read -r name status conclusion; do
        [[ -z "${name}" ]] && continue
        if [[ "${status}" != "completed" ]]; then
            warn "CI '${name}' henüz bitmedi (${status})."
            pending=1
            continue
        fi
        case "${conclusion}" in
            success|skipped) ;;
            *) warn "CI '${name}' yeşil değil (${conclusion:-bilinmiyor})."; failed=1 ;;
        esac
    done <<< "${rows}"

    if [[ "${pending}" -eq 1 ]]; then
        error "Uzak CI ${sha:0:12} için hâlâ çalışıyor. Bitmesini bekle ve tekrar dene (ya da --skip-checks ile atla)."
    fi
    if [[ "${failed}" -eq 1 ]]; then
        error "Uzak CI ${sha:0:12} için yeşil değil. Düzelt, yeni commit'i gönder ve tekrar dene (ya da --skip-checks ile atla)."
    fi

    detail "Uzak CI (${sha:0:12})" "${GREEN}GEÇTİ${NC}"
}

if [[ "${SKIP_CHECKS}" -eq 1 ]]; then
    warn "Kalite kapısı atlandı (--skip-checks): yerel kontroller, frontend zinciri ve uzak CI doğrulaması çalıştırılmadı."
else
    echo ""
    # Önce uzak CI: saniyeler sürer ve "commit'i push etmeyi unuttun" durumunu,
    # dakikalarca süren yerel zinciri çalıştırmadan yakalar.
    echo -e "  ${GRAY}→${NC} Etiketlenecek commit için uzak CI sonucu doğrulanıyor..."
    verify_remote_ci

    echo -e "  ${GRAY}→${NC} Kalite kapısı çalıştırılıyor (lint · test · analyse · security · frontend)..."
    composer --working-dir="${DIR}" lint \
        || error "composer lint başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."
    composer --working-dir="${DIR}" test \
        || error "composer test başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."
    composer --working-dir="${DIR}" analyse \
        || error "composer analyse (PHPStan) başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."
    composer --working-dir="${DIR}" security \
        || error "composer security başarısız. Düzelt ve tekrar dene (ya da --skip-checks ile atla)."
    run_frontend_gate
    verify_changelogs "${CLEAN_VERSION}"
    smoke_test_dist
    detail "Kalite kapısı" "${GREEN}GEÇTİ${NC}"
fi

extract_changelog() {
    local version="${1}"
    local changelog="${DIR}/CHANGELOG.md"
    [[ ! -f "${changelog}" ]] && return

    local escaped="${version//./\\.}"
    awk "/^## \[?${escaped}\]?/{found=1; next} found && /^## /{exit} found && /^---[[:space:]]*$/{next} found{print}" "${changelog}" \
        | sed -e 's/[[:space:]]*$//' \
        | sed -e '/./,$!d' \
        | awk 'NF{last=NR} {line[NR]=$0} END{for(i=1;i<=last;i++) print line[i]}'
}

CHANGELOG_BODY=$(extract_changelog "${CLEAN_VERSION}")

if [[ -z "${CHANGELOG_BODY}" ]]; then
    # Buraya yalnızca --skip-checks ile gelinebilir (aksi hâlde verify_changelogs durdurur).
    warn "CHANGELOG.md içinde ${CLEAN_VERSION} girişi bulunamadı — etiket gövdesiz oluşturuluyor (--skip-checks)."
    git -C "${DIR}" tag -a "${VERSION}" -m "Release ${VERSION}"
    detail "${VERSION} etiketi" "${YELLOW}OLUŞTURULDU${NC} ${GRAY}(CHANGELOG'suz)${NC}"
else
    git -C "${DIR}" tag -a "${VERSION}" -m "Release ${VERSION}" -m "${CHANGELOG_BODY}"
    detail "${VERSION} etiketi" "${GREEN}OLUŞTURULDU${NC} ${GRAY}(CHANGELOG'dan dolduruldu)${NC}"
fi

echo -e "  ${GRAY}→${NC} Remote'a gönderiliyor (${CURRENT_BRANCH} + ${VERSION})..."
git -C "${DIR}" push origin "${CURRENT_BRANCH}" "${VERSION}"
detail "Push" "${GREEN}TAMAM${NC}"

echo ""
echo -e "  ${GREEN}${VERSION} başarıyla yayınlandı!${NC}"
echo ""
echo "  Paketi yüklemek için:"
echo -e "  ${CYAN}composer require lvntr/laravel-starter-kit:\"^${CLEAN_VERSION}\"${NC}"
echo ""
