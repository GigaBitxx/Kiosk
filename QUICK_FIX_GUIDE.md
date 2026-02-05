# Quick Fix Guide - Expired Contracts Not Removing from Plots

## The Problem
Expired contracts that are more than 7 days past their expiration date are still showing in plots and not being automatically removed.

## The Solution
I've implemented comprehensive fixes to resolve this issue:

### 1. **Fixed Contract Update Logic**
**File:** `staff/update_contract.php`

**Problem:** When updating contracts via the modal, the system was allowing manual status override, which could set expired contracts back to 'active'.

**Fix:** 
- Contract status is now automatically calculated based on the contract end date
- If end date has passed → status = 'expired'
- If within 30 days of end date → status = 'renewal_needed'
- Otherwise → status = 'active'
- Maintenance runs immediately after every contract update

### 2. **Enhanced Plot Cleanup**
**File:** `staff/contract_maintenance.php`

**Problem:** When freeing plots after 7-day grace period, only the status was changed to 'available', but contract information remained.

**Fix:** When freeing plots, the system now clears ALL contract fields:
- `status` → 'available'
- `contract_status` → NULL
- `contract_start_date` → NULL
- `contract_end_date` → NULL
- `contract_type` → NULL

### 3. **Added Automatic Maintenance Triggers**
**Files:** 
- `staff/plots.php`
- `staff/maps.php`
- `staff/plot_details.php`
- `api/get_map_plots.php`
- `api/get_kiosk_map_plots.php`

**Problem:** Maintenance only ran when visiting the contracts page.

**Fix:** Maintenance now runs automatically when visiting any of these pages, ensuring expired contracts are processed more frequently.

### 4. **Created Fix Utility**
**File:** `staff/fix_expired_contracts.php`

A comprehensive utility to fix any existing issues with expired contracts.

---

## How to Fix Your Current Issue

### Step 1: Run the Fix Utility

1. Open your browser
2. Navigate to: `http://kiosk.up.railway.app/staff/fix_expired_contracts.php`
3. The script will automatically:
   - Fix any contracts missing the `contract_type` field
   - Update all contract statuses based on their dates
   - Archive expired contracts (7+ days old)
   - Free the plots and clear contract data
   - Show you a detailed report

### Step 2: Verify the Fix

1. Go to the **Plots** page (`staff/plots.php`)
2. Check that the Beta Test plot (AION-A2) is now showing as **Available**
3. Go to the **Contracts** page (`staff/contracts.php`)
4. Verify that the Beta Test contract is no longer in the "Expired Contracts" section

### Step 3: For Ongoing Maintenance (Optional but Recommended)

Set up a cron job to run maintenance daily:

```bash
# Add to your cron jobs (crontab -e)
# Run every day at 2 AM
0 2 * * * /usr/bin/php /path/to/Kiosk/staff/contract_status_checker.php
```

**OR on Windows (Task Scheduler):**
1. Open Task Scheduler
2. Create Basic Task
3. Trigger: Daily at 2:00 AM
4. Action: Start a program
5. Program: `C:\xampp\php\php.exe`
6. Arguments: `C:\xampp\htdocs\Kiosk\staff\contract_status_checker.php`

---

## What Each Script Does

### `fix_expired_contracts.php` ⭐ (Use this now!)
- **Purpose:** One-click fix for all expired contract issues
- **When to use:** When you notice expired contracts not being removed
- **What it does:** Comprehensive cleanup and fix
- **Access:** Requires staff login

### `debug_contract_maintenance.php` 🔧
- **Purpose:** Technical debugging information
- **When to use:** To understand why a specific contract isn't being archived
- **What it shows:** Detailed analysis of each expired contract
- **Access:** Requires staff login

### `contract_status_checker.php` 🔄
- **Purpose:** Scheduled maintenance (CLI)
- **When to use:** Set up as a cron job for automatic daily maintenance
- **What it does:** Same as the maintenance that runs on page loads
- **Access:** Command line or cron job

---

## Prevention

The system will now automatically prevent this issue because:

1. ✅ Contract status is calculated automatically when updating
2. ✅ Maintenance runs on multiple pages (not just contracts page)
3. ✅ All contract data is cleared when plots are freed
4. ✅ Fix utility is available for quick repairs

---

## Summary of Changes

### Modified Files:
- ✅ `staff/update_contract.php` - Auto-calculate contract status
- ✅ `staff/contract_maintenance.php` - Clear all contract fields when freeing
- ✅ `staff/plots.php` - Added maintenance trigger
- ✅ `staff/maps.php` - Added maintenance trigger
- ✅ `staff/plot_details.php` - Added maintenance trigger
- ✅ `api/get_map_plots.php` - Added maintenance trigger
- ✅ `api/get_kiosk_map_plots.php` - Added maintenance trigger

### New Files:
- 🆕 `staff/fix_expired_contracts.php` - Fix utility with web interface
- 🆕 `staff/debug_contract_maintenance.php` - Debug script
- 🆕 `database/fix_contract_types.php` - CLI script to fix contract types
- 🆕 `CONTRACT_EXPIRATION_SYSTEM.md` - Complete documentation
- 🆕 `QUICK_FIX_GUIDE.md` - This file

---

## Need Help?

If the issue persists after running the fix utility:

1. Check the debug script: `/staff/debug_contract_maintenance.php`
2. Look for the specific contract and see why it's not being archived
3. Common reasons:
   - Contract type is not 'temporary'
   - Contract end date is NULL
   - Deceased record was created less than 7 days ago

---

**Last Updated:** February 5, 2026
