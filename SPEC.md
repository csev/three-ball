# Three-Ball Tournament Functional Spec

## 1. Goal
Build a simple website for a pub-hosted 3-ball chip tournament. The site must support:

- fixed player queue
- timed turns
- score entry
- automatic chip loss on score > 4
- automatic chip loss on timeout / no-show
- public leaderboard display
- simple tournament state persistence in SQLite

## 2. Core domain concepts

### Tournament
A single running event.

Fields:
- id
- name
- venue_name
- starting_pot
- current_pot
- timer_seconds
- chips_per_player
- status (`setup`, `running`, `finished`)
- current_player_id
- current_turn_started_at
- current_turn_expires_at
- current_cycle_number
- created_at

### Player
A player in the queue.

Fields:
- id
- tournament_id
- display_name
- queue_position
- chips_remaining
- is_eliminated
- eliminated_at
- created_at

### Turn
One completed or forfeited turn.

Fields:
- id
- tournament_id
- player_id
- cycle_number
- turn_number
- score_nullable
- result_type (`scored`, `timeout`, `manual_penalty`)
- chip_delta
- payout_delta
- note_nullable
- created_at

## 3. Tournament flow

1. Tournament is created in setup state.
2. Players are entered in queue order.
3. Tournament starts.
4. First active player becomes current player.
5. Turn timer starts at 60 seconds.
6. Runner enters either:
   - score 1..9, or
   - timeout / no-show
7. Business rules are applied.
8. If player reaches 0 chips, player is eliminated.
9. Advance to next active player in queue.
10. If end of queue is reached, wrap to first active player and increment cycle if needed.
11. Repeat until only one player remains, or operator ends event manually.

## 4. Rules currently encoded

### Chip rule
- If score <= 4: player keeps chip count unchanged.
- If score > 4: player loses 1 chip.
- If timeout/no-show: player loses 1 chip.
- If chips_remaining <= 0: player is marked eliminated.

### Queue rule
- Queue is fixed by queue_position.
- Current player always advances to next non-eliminated player.
- Eliminated players are skipped.
- Queue wraps around.

### Timer rule
- Each turn has timer_seconds, default 60.
- If client timer reaches zero, timeout API may be called automatically.
- Server-side state still decides whether the turn is expired.

## 5. UI requirements

### setup.php
Must allow:
- tournament name
- venue name
- starting pot
- timer seconds
- players list pasted one name per line
- start/reset tournament

### control.php
Must show:
- current player name
- up next player name
- countdown timer
- chips remaining for current player
- score buttons (1,2,3,4,5,6,7,8,9)
- timeout button
- recent turn history

### display.php
Must show:
- tournament title
- current pot
- current player / up next
- countdown timer
- leaderboard in queue order
- chips remaining
- elimination status
- recent results

## 6. Deferred / partially manual rules
The handwritten rule sheets mention jackpot and tie payouts. These are not fully automated in this starter.

Deferred items:
- first-5-round bonus prize logic
- pot depletion based on score tie payouts
- unique-lowest-score jackpot detection
- split payouts when pot is insufficient

For now, payout logic should be recorded as notes or handled manually.

## 7. Suggested next Cursor tasks
1. add undo-last-turn
2. automate payout rules after clarifying exact semantics of “round” vs “cycle”
3. add manual pot adjustment buttons
4. add player mobile page by QR code
5. add operator PIN auth
