# Three-Ball Tournament Starter

A lightweight PHP + SQLite starter for Mike's pub 3-ball tournament.

## What is included

- `setup.php` — create tournament, set pot, add players, order queue
- `control.php` — tournament runner screen with timer and score buttons
- `display.php` — public TV leaderboard / up-next screen
- `api/state.php` — current tournament state as JSON
- `api/submit_score.php` — submit a score for the current player
- `api/timeout.php` — apply automatic chip loss for no-show / expired timer
- `lib/*.php` — database, rules, and helper functions
- SQLite database auto-created in `data/threeball.sqlite`

## Assumptions encoded so far

- Players are in a fixed circular queue.
- When the queue reaches the end, it wraps to the first remaining active player.
- Each player starts with 5 chips.
- Score `<= 4` keeps all chips.
- Score `> 4` loses one chip.
- A timeout/no-show loses one chip and ends the turn.
- Eliminated players are skipped.
- Each turn has a 60-second countdown.
- Bonus/jackpot logic is stored in a turn note for now, not fully automated.

## Quick start

1. Put this folder under an Apache/PHP web root.
2. Make sure PHP has SQLite enabled.
3. Open `setup.php`.
4. Enter tournament details and players.
5. Start the tournament.
6. Open `control.php` on the staff device.
7. Open `display.php` on the TV browser.

## PHP version

Written to be boring and compatible: PHP 8.0+ should be fine.

## Next likely improvements

- Undo last turn
- Manual payout buttons / pot deductions
- Player QR view
- Better mobile styling
- Authentication for control screen
