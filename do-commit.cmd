@echo off
cd /d E:\Github\EtchFusion-Suite

echo Staging changes...
git add -A

echo Committing...
git commit -m "Fix: Resolve ACSS class double-skip bug in class migration - Removed ACSS-availability drop check from get_css_classes() in class-base-element.php lines 106-108 - Classes are correctly mapped via style_map - ACSS availability is a separate concern - Added regression tests to BaseElementModifierClassTest.php - Co-authored-by: Copilot [223556219+Copilot@users.noreply.github.com]"

echo Pushing...
git push

echo.
echo Last 5 commits:
git log --oneline -5
