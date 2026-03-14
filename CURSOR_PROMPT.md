You are helping me build a lightweight PHP + SQLite web app for a pub-hosted 3-ball pool tournament. Please work inside the existing starter codebase and improve it without introducing heavy frameworks.

## Stack constraints
- PHP 8+
- SQLite
- Vanilla JavaScript
- Plain CSS
- Keep deployment simple for Apache/PHP shared hosting or a normal VPS
- No composer dependencies unless absolutely necessary

## What this app does
This is not a normal bracket tournament. It is a fixed circular queue of players.

- Each player starts with 5 chips.
- There is a single active player at a time.
- Each turn has a 60-second countdown.
- If a player does not show up in time, they automatically lose one chip and their turn ends.
- When a player completes a turn, the operator enters a score.
- If score <= 4, no chip is lost.
- If score > 4, lose one chip.
- When chips reach 0, the player is eliminated.
- Eliminated players are skipped.
- Queue wraps around forever until tournament ends.

## Current priorities
1. Make the starter code reliable and runnable.
2. Improve the UI for setup, control, and display.
3. Add undo last turn.
4. Add manual pot adjustment.
5. Only after that, help me automate payout logic.

## Important modeling note
The handwritten rules mention jackpots and tie payouts, but the semantics are still a little fuzzy, especially around what exactly counts as a “round” versus a full queue cycle. Do not aggressively automate these rules until the code clearly isolates them in a rules module and keeps them easy to revise.

## Coding style
- Keep files small and readable.
- Prefer simple functions over classes unless classes make things much clearer.
- Use prepared statements.
- Add comments only where logic is subtle.
- Do not replace the whole codebase with a framework.
- Preserve existing URLs and flow if possible.

## Immediate tasks
- Review the current schema and page flow.
- Fix any obvious bugs.
- Make control.php robust for timer expiry and accidental double submit.
- Add an undo-last-turn action.
- Add a small settings area for manual pot adjustments and notes.
- Improve display.php so it looks good on a TV at a distance.

When making changes, explain them briefly and keep them incremental.
