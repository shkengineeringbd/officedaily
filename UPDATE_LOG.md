# Office Daily — Update 08

Date: 23 November 2026 (Asia/Dhaka)

Deployment verified: Update 08 code is live at https://shkg.info. Published JavaScript/CSS matched the checked source; the authenticated API correctly rejected an anonymous request. Production cleanup mode was confirmed as **on-access**, so the Hostinger Cron Job remains outstanding. No open Hostinger session was available to configure it.

This is the identifier for the current requested update batch, not a count inferred from earlier releases.

## Included

1. **Visible attendance actions:** My attendance stays above Task list. Check-in and Check-out buttons remain visible; Admin Preview shows them disabled with a read-only explanation.
2. **Two fresh selfies:** Both check-in and check-out require a new camera capture. Gallery uploads are not offered. The recorded time is when the server receives the selfie submission, displayed in Bangladesh time. This is not facial recognition or proof of liveness.
3. **Task assignment:** Admin and MD can assign tasks to all active assigned users, including management. GM, AGM, CFO and Branch Managers can assign tasks to active Staff/Officers in their own branch. The API enforces the same limits as the employee picker.
4. **Task provenance:** New assigned tasks and self-created daily work record the creator's name and post. These appear in Task list, task details and daily work. Older tasks without recorded provenance are labelled honestly; no creator is guessed.
5. **Profile preview explanation:** The selector shows whether a user's identity profile is approved. When final approval is missing, the preview explains why only the profile page is available. Existing approval and access checks remain enforced.
6. **Temporary selfie storage:** Check-in and check-out photos are encrypted separately, stop being served after 24 hours each, and are excluded from application backup data. Expired photos are deleted during API activity. Installation also attempts to register a once-per-minute database cleanup event if hosting permissions and the database event scheduler allow it.
7. **Remarks and status review:** GM, CFO, MD and other authorized managers can open visible work and leave remarks. Staff/Officers can complete their own work. Authorized managers can reopen it as Incomplete (stored as Remaining) or In Progress. Status changes record the actor, post, before/after states and time; previous daily snapshots remain unchanged. Reopened work carries forward.
8. **People, Salary & Attendance UI:** Labelled SVG icons, section cards, clear active states, readable form labels, consistent action buttons, employee selection and responsive layouts. Daily work links to its remarks/review panel.

## Hosting follow-up

If the database event cannot be registered, a Hostinger Cron Job is still required for deletion while nobody is using the site. Run this PHP script once per minute:

`public_html/bin/cleanup-attendance-selfies.php`

Use the account's actual absolute hosting path in Hostinger's PHP Cron Job configuration. The script is CLI-only. Until that job is configured, expired photos are inaccessible after 24 hours and are physically removed on subsequent API activity. Attendance records are kept.

## Validation

- PHP runtime tests: role/branch assignment limits, creator metadata, own completion, manager reopening, unchanged historical snapshots and carry-forward.
- PHP runtime tests: challenge/mode binding, image validation, separate encrypted selfies, server timestamps, duplicate prevention and expiry without deleting attendance.
- Existing profile-payment permissions and read-only preview tests.
- Rendered React DOM checks for Staff, GM, AGM, CFO, MD and Admin; attendance placement, New task visibility, assignment options, creator display and status controls.
- Camera flow tests with mocked browser camera: separate captures, preview restrictions, disabled controls and camera track cleanup.

Real device camera permission and authenticated production workflows still need an on-device check; automated camera tests use mocks.

## Responsive refinement — 23 November 2026

- Collapsible navigation through tablet widths (900px); desktop navigation remains visible.
- Phone forms use one column, readable 16px inputs and touch-sized controls. Profile fields stack within narrow tablet content columns.
- Tables scroll inside their own container. Headers, action groups and long employee names wrap within available space.
- Task dialogs use the available device height, including landscape and browser toolbar changes.
- The preview toolbar measures its actual height after wrapping or rotation, keeping the workspace and navigation below it.
- Browser regression checks cover 320–1920px widths, phone landscape, menu opening, task drawer, People/Salary/Budget layout fixtures, attendance, profile and operations. Fixtures do not validate authenticated production data or real camera permissions.
