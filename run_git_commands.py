import subprocess
import os

os.chdir(r'E:\Github\EtchFusion-Suite')

# Stage changes
result1 = subprocess.run(['git', 'add', '-A'], capture_output=True, text=True)
print("Stage output:", result1.stdout, result1.stderr)

# Commit
commit_msg = """Fix: Resolve ACSS class double-skip bug in class migration

- Removed ACSS-availability drop check from get_css_classes() in class-base-element.php lines 106-108
- When a class existed in style_map but efs_acss_inline_style_map was empty, classes were silently dropped
- This caused ALL mapped classes to disappear from migrated HTML on sites without ACSS or after CSS migration reset
- Now: always output class name if present in style_map, regardless of inline-style availability
- Added regression tests to BaseElementModifierClassTest.php
- Classes are correctly mapped via style_map - ACSS availability is a separate concern

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"""

result2 = subprocess.run(['git', 'commit', '-m', commit_msg], capture_output=True, text=True)
print("Commit output:", result2.stdout, result2.stderr)

# Show last 5 commits
result3 = subprocess.run(['git', 'log', '--oneline', '-5'], capture_output=True, text=True)
print("Last 5 commits:\n", result3.stdout)
