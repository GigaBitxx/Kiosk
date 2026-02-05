# Contract Expiration & Plot Cleanup System

## Overview
This document explains the automated system that manages expired contracts and automatically frees plots after a 7-day grace period.

## How It Works

### 1. Contract Status Updates
The system automatically updates contract statuses based on their end dates:

- **Active** → **Renewal Needed**: When a contract is within 30 days of expiration
- **Active/Renewal Needed** → **Expired**: When a contract reaches its end date
- **Expired** → **Archived** (with plot freed): 7 days after expiration

### 2. Seven-Day Grace Period
When a contract expires, the system provides a **7-day grace period** before taking action:

- **Days 0-6**: Contract is marked as "EXPIRED" with status "(Pending Archive - X days left)"
- **Day 7+**: Deceased records are automatically archived and the plot is freed

### 3. What Happens After 7 Days

When the grace period ends, the system automatically:

1. **Archives deceased records** from the plot to `archived_deceased_records` table
2. **Deletes deceased records** from the `deceased_records` table
3. **Frees the plot** by setting status to "Available"
4. **Clears contract information** from the plot:
   - `contract_status` → NULL
   - `contract_start_date` → NULL
   - `contract_end_date` → NULL
   - `contract_type` → NULL

### 4. Conditions for Automatic Archiving

The system only archives and frees plots when:

- The contract has been expired for **7+ days**
- The contract is of type **"temporary"**
- The deceased record was created **7+ days ago** (provides grace period for newly added records)

## Implementation Details

### Core Function
The main logic is in `/staff/contract_maintenance.php`:

```php
function run_contract_maintenance($conn, $is_cli = false)
```

This function:
- Updates expired contracts
- Archives deceased records after 7-day grace period
- Frees plots and clears contract data
- Updates renewal-needed status

### Automatic Execution
The maintenance runs automatically when these pages are accessed:

**Staff Pages:**
- `/staff/contracts.php` - Contract Management page
- `/staff/plots.php` - Plots listing page
- `/staff/maps.php` - Maps view page
- `/staff/plot_details.php` - Individual plot details

**API Endpoints:**
- `/api/get_map_plots.php` - Map data for staff
- `/api/get_kiosk_map_plots.php` - Map data for kiosk

### Manual Execution (Cron Job)
For best results, set up a cron job to run the maintenance script daily:

```bash
# Add to crontab to run daily at 2 AM
0 2 * * * /usr/bin/php /path/to/Kiosk/staff/contract_status_checker.php
```

The CLI script at `/staff/contract_status_checker.php` provides detailed output about what was updated.

## User Interface

### Contract Management Page
Expired contracts are displayed with their status:

- **EXPIRED** - Contract has expired but is within 7-day grace period
- **EXPIRED (Pending Archive - 4 days left)** - Shows countdown until automatic archiving

### Plots Page
After the 7-day grace period:
- Plot status changes from "Occupied" to "Available"
- Deceased information is no longer shown
- Plot becomes available for new contracts

## Database Schema

### Tables Involved

1. **`plots`** - Main plot information
   - `status`: available/occupied/reserved
   - `contract_status`: active/renewal_needed/expired/NULL
   - `contract_start_date`, `contract_end_date`, `contract_type`

2. **`deceased_records`** - Active deceased records
   - Deleted after 7-day grace period

3. **`archived_deceased_records`** - Historical deceased records
   - Preserves records after archiving
   - Includes `reason` field (e.g., "Contract expired")
   - Includes `archived_by` (user who triggered, or NULL for automatic)

## Benefits

1. **Automatic Plot Management**: No manual intervention needed to free expired plots
2. **Grace Period**: 7 days allows time to renew contracts before archiving
3. **Data Preservation**: Deceased records are archived, not permanently deleted
4. **Audit Trail**: Archives include reason and user who triggered the archiving
5. **Consistent State**: Runs on every page load and via cron job

## Troubleshooting

### Plot not freeing after 7 days?
Check that:
1. Contract type is "temporary" (permanent contracts don't auto-expire)
2. Deceased record was created more than 7 days ago
3. The maintenance script is running (visit any staff page or check cron job)

### Manual override needed?
Staff can manually change plot status from the Plot Details page, which will:
- Archive the deceased record immediately
- Free the plot regardless of grace period
- Log the manual action

## Future Enhancements

Potential improvements:
- Email notifications before expiration
- Configurable grace period (currently hardcoded to 7 days)
- Dashboard showing upcoming expirations
- Batch renewal interface for multiple contracts
