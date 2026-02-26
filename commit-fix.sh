#!/bin/bash
cd E:\\Github\\EtchFusion-Suite

git add -A

git commit -m "Fix: Resolve ACSS class double-skip bug in class migration

- Removed ACSS-availability drop check from get_css_classes() in class-base-element.php lines 106-108
- When a class existed in style_map but efs_acss_inline_style_map was empty, classes were silently dropped
- This caused ALL mapped classes to disappear from migrated HTML on sites without ACSS or after CSS migration reset
- Now: always output class name if present in style_map, regardless of inline-style availability
- Added regression tests to BaseElementModifierClassTest.php
- Classes are correctly mapped via style_map - ACSS availability is a separate concern

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"

echo "=== Last 5 commits ==="
git log --oneline -5
