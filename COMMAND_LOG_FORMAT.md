# BpMessage Command Log Format

## Overview

The `mautic:bpmessage:process` command has been updated to output simple, plain text logs that match the standard cron log format used by Mautic.

## Changes Made

### Removed
- SymfonyStyle decorations (sections, tables, color tags, boxes)
- Fancy formatting (success/error/warning boxes)
- Table rendering for failed messages

### Added
- Plain text output using `OutputInterface->writeln()`
- Simple line-by-line logging
- Consistent format matching existing cron logs

## Output Format Comparison

### Before (with SymfonyStyle)

```
===========================================
Processing lot #5
===========================================

 [ERROR] Failed to process lot #5

 ! [WARNING] Error details:

Batch 0 failed: HTTP 400: {"messages":["'Area Code' must not be empty."]}


========================================
Failed Messages Sample (first 5):
========================================

 --------- --------- --------- ----------------------
  Queue ID  Lead ID   Retries   Error Message
 --------- --------- --------- ----------------------
  15        123       1         HTTP 400: 'Area C...
  16        124       1         HTTP 400: 'Area C...
 --------- --------- --------- ----------------------

 ! Fix the error and retry with: php bin/console mautic:bpmessage:process --lot-id=5
 ! Or check logs: tail -100 var/logs/mautic_prod.log | grep 'lot.*5'
```

### After (plain text)

```
Processing lot #5
Failed to process lot #5
Error details:
Batch 0 failed: HTTP 400: {"messages":["'Area Code' must not be empty."]}

Failed Messages Sample (first 5):
  Queue ID: 15, Lead ID: 123, Retries: 1, Error: HTTP 400: 'Area Code' must not be empty.
  Queue ID: 16, Lead ID: 124, Retries: 1, Error: HTTP 400: 'Area Code' must not be empty.

Fix the error and retry with: php bin/console mautic:bpmessage:process --lot-id=5
Or check logs: tail -100 var/logs/mautic_prod.log | grep 'lot.*5'

```

## Example Outputs

### 1. Processing Open Lots (Success)

```
Processing BpMessage open lots
3 total lot(s) processed in batches
3 lot(s) succeeded

```

### 2. Processing Open Lots (With Failures)

```
Processing BpMessage open lots
5 total lot(s) processed in batches
4 lot(s) succeeded
1 lot(s) failed

```

### 3. Processing Specific Lot (Success)

```
Processing lot #10
Lot #10 processed successfully
```

### 4. Processing Specific Lot (Failure)

```
Processing lot #5
Failed to process lot #5
Error details:
Batch 0 failed: HTTP 400: {"messages":["'Area Code' must not be empty."]}

Failed Messages Sample (first 5):
  Queue ID: 15, Lead ID: 123, Retries: 1, Error: HTTP 400: 'Area Code' must not be empty.
  Queue ID: 16, Lead ID: 124, Retries: 1, Error: HTTP 400: 'Area Code' must not be empty.

Fix the error and retry with: php bin/console mautic:bpmessage:process --lot-id=5
Or check logs: tail -100 var/logs/mautic_prod.log | grep 'lot.*5'

```

### 5. Retry Failed Messages

```
Retrying BpMessage failed messages
Maximum retries: 3
5 message(s) retried

```

### 6. Exception Handling

```
Processing lot #99
Error processing lot #99: Lot not found
Exception type: Doctrine\ORM\EntityNotFoundException
Stack trace:
#0 /var/www/html/plugins/MauticBpMessageBundle/Model/BpMessageModel.php(123): ...
#1 /var/www/html/plugins/MauticBpMessageBundle/Command/ProcessBpMessageQueuesCommand.php(106): ...
```

## Benefits

✅ **Clean Logs**: No formatting characters cluttering the output

✅ **Consistent**: Matches the format of other Mautic cron commands

✅ **Parseable**: Easy to parse with grep, awk, or log aggregation tools

✅ **Readable**: Simple, straightforward text that's easy to read in log files

✅ **Compact**: Smaller log file sizes without formatting overhead

## Technical Details

### Changes to ProcessBpMessageQueuesCommand.php

1. **Removed SymfonyStyle import** (line 13)
2. **Removed SymfonyStyle usage** throughout all methods
3. **Updated method signatures** to remove `SymfonyStyle $io` parameter
4. **Converted all output** to use `OutputInterface->writeln()`

### Methods Modified

- `execute()` - Removed SymfonyStyle creation
- `processSpecificLot()` - Converted to plain text
- `retryFailedMessages()` - Converted to plain text
- `processOpenLots()` - Converted to plain text

## Usage

The command usage remains the same:

```bash
# Process open lots
php bin/console mautic:bpmessage:process

# Force close all lots
php bin/console mautic:bpmessage:process --force-close

# Process specific lot
php bin/console mautic:bpmessage:process --lot-id=5

# Retry failed messages
php bin/console mautic:bpmessage:process --retry --max-retries=3
```

## Cron Job Output

When run via CronJob, the output will now appear in logs as:

```
[2025-11-21 18:30:00] Processing BpMessage open lots
[2025-11-21 18:30:01] 3 total lot(s) processed in batches
[2025-11-21 18:30:01] 3 lot(s) succeeded
[2025-11-21 18:30:01]
```

This matches the format of other Mautic cron commands like `mautic:segments:update`, `mautic:campaigns:trigger`, etc.
