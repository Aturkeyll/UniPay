#!/usr/bin/env bash
#
# push.sh: commit everything in this directory and push it to GitHub.
#
#   bash /home/mint/UniPay/push.sh
#   bash /home/mint/UniPay/push.sh "your commit message"
#
# Refuses to push if anything that looks like a secret is staged. A password
# pushed to a public repo is public forever, even if you delete it in the next
# commit, because the object stays in the history.

set -uo pipefail

REPO_URL="${REPO_URL:-https://github.com/Aturkeyll/UniPay.git}"
BRANCH="${BRANCH:-main}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MSG="${1:-Update UniPay: Interledger payments, live rates, auth hardening}"

BOLD=$'\e[1m'; GREEN=$'\e[32m'; YELLOW=$'\e[33m'; RED=$'\e[31m'; OFF=$'\e[0m'
ok()   { echo "  ${GREEN}[ok]${OFF}   $*"; }
warn() { echo "  ${YELLOW}[warn]${OFF} $*"; }
die()  { echo "  ${RED}[stop]${OFF} $*"; echo; exit 1; }
step() { echo; echo "${BOLD}==> $*${OFF}"; }

cd "$DIR" || die "cannot enter $DIR"
echo "${BOLD}Push UniPay to GitHub${OFF}"
echo "  from: $DIR"
echo "  to:   $REPO_URL ($BRANCH)"

# ---------------------------------------------------------------------------
step "1/6  Git and authentication"

command -v git >/dev/null || { sudo apt-get install -y git || die "git install failed"; }
ok "git $(git --version | awk '{print $3}')"

if [ -z "$(git config --global user.email 2>/dev/null)" ]; then
  read -rp "  Your GitHub email: " GH_EMAIL
  read -rp "  Your name: " GH_NAME
  git config --global user.email "$GH_EMAIL"
  git config --global user.name "$GH_NAME"
fi
ok "committing as $(git config --global user.name) <$(git config --global user.email)>"

if command -v gh >/dev/null; then
  if gh auth status >/dev/null 2>&1; then
    ok "GitHub CLI already authenticated"
  else
    warn "GitHub CLI installed but not logged in"
    echo "  running: gh auth login"
    gh auth login || die "gh auth login failed"
  fi
  gh auth setup-git >/dev/null 2>&1 && ok "git credentials wired to gh"
else
  warn "GitHub CLI (gh) not installed"
  echo "  Easiest: sudo apt install gh   then re-run this script."
  echo "  Otherwise git will prompt for a username and a Personal Access Token."
  echo "  Your account password will NOT work; create a token at"
  echo "    https://github.com/settings/tokens  (scope: repo)"
  echo
  read -rp "  Continue without gh? [y/N] " yn
  [[ "$yn" =~ ^[Yy]$ ]] || exit 0
  # Cache the token for 8 hours so it isn't retyped on every push.
  git config --global credential.helper 'cache --timeout=28800'
fi

# ---------------------------------------------------------------------------
step "2/6  Repository"

if [ ! -d .git ]; then
  git init -q
  git branch -M "$BRANCH"
  ok "initialised a new repository"
else
  ok "already a git repository"
  git branch -M "$BRANCH" 2>/dev/null
fi

if git remote get-url origin >/dev/null 2>&1; then
  CURRENT=$(git remote get-url origin)
  if [ "$CURRENT" != "$REPO_URL" ]; then
    git remote set-url origin "$REPO_URL"
    ok "remote updated to $REPO_URL"
  else
    ok "remote already set"
  fi
else
  git remote add origin "$REPO_URL"
  ok "remote added"
fi

# ---------------------------------------------------------------------------
step "3/6  Staging"

[ -f .gitignore ] || die ".gitignore is missing. Refusing to stage without it."
git add -A
ok "$(git diff --cached --name-only | wc -l) file(s) staged"

# ---------------------------------------------------------------------------
step "4/6  Secret check"

# A secret pushed to a public repo stays in the history even if the next commit
# removes it. Cheaper to stop here.
BLOCKED=0

for f in .db_password .env; do
  if git diff --cached --name-only | grep -qx "$f"; then
    echo "  ${RED}[stop]${OFF} $f is staged"
    BLOCKED=1
  fi
done

if git diff --cached --name-only | grep -qE '^logs/|\.log$|db\.php\.bak'; then
  warn "log or backup files are staged:"
  git diff --cached --name-only | grep -E '^logs/|\.log$|db\.php\.bak' | sed 's/^/      /'
  BLOCKED=1
fi

# Look for live credentials in the staged content itself.
STAGED_HITS=$(git diff --cached -U0 \
  | grep -E '^\+' \
  | grep -viE 'getenv|password_verify|password_hash|name="password"|\$password|rafikiEnv|PHPEOF' \
  | grep -oiE '(sk-ant-[A-Za-z0-9_-]{10,}|ghp_[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16})' \
  | head -5)

if [ -n "$STAGED_HITS" ]; then
  echo "  ${RED}[stop]${OFF} what look like live credentials are staged:"
  echo "$STAGED_HITS" | sed 's/^/      /'
  BLOCKED=1
fi

if [ "$BLOCKED" = "1" ]; then
  echo
  echo "  Unstage them, add them to .gitignore, then run this again:"
  echo "      git reset"
  die "refusing to push"
fi
ok "no secrets in the staged changes"

# The playground admin secret is published in Rafiki's own repo, so it is not
# a leak, but say so plainly rather than let it look like one.
if git diff --cached | grep -q 'iyIgCprjb9uL8wFckR'; then
  warn "rafiki_config.php contains the Local Playground admin secret."
  warn "That value is public in Rafiki's repo, so this is safe to commit,"
  warn "but never reuse it on a real instance."
fi

# ---------------------------------------------------------------------------
step "5/6  Commit"

if git diff --cached --quiet; then
  ok "nothing to commit, working tree matches the last commit"
else
  git commit -q -m "$MSG" || die "commit failed"
  ok "committed: $MSG"
fi

# ---------------------------------------------------------------------------
step "6/6  Push"

git fetch origin "$BRANCH" >/dev/null 2>&1
REMOTE_EXISTS=$?

if [ "$REMOTE_EXISTS" = "0" ] && ! git merge-base --is-ancestor "origin/$BRANCH" HEAD 2>/dev/null; then
  echo
  warn "The remote $BRANCH has commits your local branch does not."
  warn "This happens when the GitHub repo already had the older UniPay in it."
  echo
  echo "    1) Keep BOTH histories, local files win where they clash (recommended)"
  echo "    2) Replace the remote entirely with what is here (rewrites history)"
  echo "    3) Cancel"
  echo
  read -rp "  Choose [1/2/3]: " choice

  case "$choice" in
    1)
      git merge origin/"$BRANCH" --allow-unrelated-histories -X ours \
        -m "Merge existing remote history" || die "merge failed, resolve by hand then re-run"
      ok "histories merged"
      echo
      warn "Files kept from the old remote (delete any you do not want):"
      git diff --name-only HEAD@{1} HEAD 2>/dev/null | head -20 | sed 's/^/      /'
      ;;
    2)
      warn "the previous remote history will no longer be reachable"
      read -rp "  Type REPLACE to confirm: " confirm
      [ "$confirm" = "REPLACE" ] || die "cancelled"
      git push -u origin "$BRANCH" --force && ok "force pushed" || die "push failed"
      echo; echo "${BOLD}${GREEN}Done.${OFF} https://github.com/Aturkeyll/UniPay"; echo
      exit 0
      ;;
    *) die "cancelled" ;;
  esac
fi

if git push -u origin "$BRANCH"; then
  ok "pushed"
else
  echo
  warn "Push failed. Most likely causes:"
  echo "      - Authentication. With gh: gh auth login"
  echo "        Without gh, the password prompt needs a Personal Access Token,"
  echo "        not your account password: https://github.com/settings/tokens"
  echo "      - No write access to $REPO_URL"
  die "not pushed"
fi

echo
echo "${BOLD}${GREEN}Done.${OFF}  https://github.com/Aturkeyll/UniPay"
echo
echo "  Next time, just: bash push.sh \"what changed\""
echo
