#!/usr/bin/env python3
import subprocess
import os
import sys

os.chdir(r'E:\Github\EtchFusion-Suite')

try:
    # Stage all changes
    print("📦 Staging changes...")
    result = subprocess.run(['git', 'add', '-A'], capture_output=True, text=True, check=True)
    if result.stdout:
        print(result.stdout)
    
    # Commit
    print("✍️  Committing...")
    commit_msg = """Fix: Resolve ACSS class double-skip bug in class migration

- Removed ACSS-availability drop check from get_css_classes() in class-base-element.php lines 106-108
- When a class existed in style_map but efs_acss_inline_style_map was empty, classes were silently dropped
- This caused ALL mapped classes to disappear from migrated HTML on sites without ACSS or after CSS migration reset
- Now: always output class name if present in style_map, regardless of inline-style availability
- Added regression tests to BaseElementModifierClassTest.php
- Classes are correctly mapped via style_map - ACSS availability is a separate concern

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"""
    
    result = subprocess.run(['git', 'commit', '-m', commit_msg], capture_output=True, text=True, check=True)
    print(result.stdout)
    if result.stderr:
        print(result.stderr)
    
    # Push
    print("\n🚀 Pushing to remote...")
    result = subprocess.run(['git', 'push'], capture_output=True, text=True, check=True)
    print(result.stdout)
    if result.stderr:
        print(result.stderr)
    
    # Show log
    print("\n📋 Last 5 commits:")
    result = subprocess.run(['git', 'log', '--oneline', '-5'], capture_output=True, text=True, check=True)
    print(result.stdout)
    
    print("\n✅ Done!")
    
except subprocess.CalledProcessError as e:
    print(f"❌ Error: {e}")
    print(f"stdout: {e.stdout}")
    print(f"stderr: {e.stderr}")
    sys.exit(1)
except Exception as e:
    print(f"❌ Unexpected error: {e}")
    sys.exit(1)
