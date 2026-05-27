# Google Sheet import v1 PR status

PR statusz: mar letezett, nyitva

PR URL: `https://github.com/zolihajduserious-dot/mvm-mezoenergy/pull/2`

PR szam: `#2`

PR cim: `Google Sheet Facebook lead import v1`

PR draft statusz: nem draft

GitHub merge statusz: `CLEAN`

Kezi PR link:

```text
https://github.com/zolihajduserious-dot/mvm-mezoenergy/compare/main...feature/google-sheet-facebook-lead-import-v1
```

GitHub CLI ellenorzes:

```powershell
gh pr list --base main --head feature/google-sheet-facebook-lead-import-v1 --state open --json number,title,url,state
```

Source branch: `feature/google-sheet-facebook-lead-import-v1`

Target branch: `main`

Aktualis commit a PR ellenorzesekor: `80dbd6e`

Merge statusz: NINCS MERGE

Deploy statusz: NINCS DEPLOY

Eles import statusz: NEM FUTOTT

## Kovetkezo kezi lepesek

1. PR diff online atnezese.
2. Secret ellenorzes GitHubon.
3. Reviewer jovahagyas.
4. Merge main agba.
5. Nethely deploy.
6. `database/lead_imports.sql` futtatas.
7. Token beallitas.
8. PowerShell endpoint tesztek.
9. Google Sheet egytesztsoros proba.
10. Csak siker utan trigger.

## Fontos tiltasok

- Ne merge-olj automatikusan.
- Ne deployolj Nethely productionre a PR review elott.
- Ne futtass eles importot review es deploy runbook nelkul.
- Ne irj be valodi tokent PR-ba, dokumentacioba vagy Google Sheet cellaba.
