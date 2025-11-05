# Bricks Builder Auto-Setup

This guide explains how to set up automatic Bricks Builder installation for development.

## 🎯 What This Does

When you run `npm run dev`, the system will:

1. ✅ Start both WordPress environments (Port 8888 + 8889)
2. 🔍 Check for Bricks license file
3. 🧱 Auto-install Bricks Builder if license is available
4. 🎯 Show the correct Etch Fusion interface

## 📋 Setup Options

### Option 1: Auto-Install (Recommended)

Create a license file in one of these locations:

```bash
# In project root
echo "your-bricks-license-key" > bricks-license.txt

# Or in home directory
echo "your-bricks-license-key" > ~/.bricks-license
```

### Option 2: Manual Install

If you don't have a license file:

1. Start development: `npm run dev`
2. Go to: `http://localhost:8888/wp-admin`
3. Login: `admin` / `password`
4. Go to: Plugins → Add Plugin
5. Upload Bricks Builder zip file
6. Activate

## 🔄 Environment States

### Port 8888 (Development/Source)

- **Without Bricks**: Shows "No Compatible Builder Detected"
- **With Bricks**: Shows Bricks migration interface

### Port 8889 (Tests/Target)

- **Always**: Shows Etch PageBuilder interface

## 🚀 Quick Start

```bash
# 1. Add your license (optional)
echo "your-license-key" > bricks-license.txt

# 2. Start development
npm run dev

# 3. Check both sites
# http://localhost:8888/wp-admin (Bricks source)
# http://localhost:8889/wp-admin (Etch target)
```

## 🎯 Result

After setup, you'll have:

- ✅ **Port 8888**: Bricks Builder ready for migration
- ✅ **Port 8889**: Etch PageBuilder ready as target
- 🎯 **Perfect development environment** for Etch Fusion testing

## 🐛 Troubleshooting

**Bricks not installing?**

- Check license file location
- Verify license key is valid
- Check console output for errors

**Still seeing "No Compatible Builder"?**

- Wait 1-2 minutes after `npm run dev`
- Refresh the WordPress admin page
- Check if Bricks is activated in Plugins

**Manual installation always works** as fallback!
