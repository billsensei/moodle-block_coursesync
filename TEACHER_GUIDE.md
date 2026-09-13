# Course Sync: teacher guide

Course Sync lets you pull activities that have been added to a course on
**another** Moodle site into a course on **this** site, without recreating
them by hand. This guide walks through adding the block, connecting it, and
running a sync - no technical knowledge required. If something below asks
for information you don't have (a web address or a token), that comes from
your site administrator or from whoever manages the other site - see
"Before you start" below.

## Before you start

You'll need two things from whoever administers the **other** ("remote" or
"source") site - the one your activities currently live on:

1. **That site's web address** (e.g. `https://theirsite.example.edu`).
2. **A token** - a long code that lets this block read from that site on
   your behalf. Your administrator (or theirs) generates this for you; you
   just need to be handed it once. Treat it like a password - anyone who has
   it can pull data using your access, so don't share it beyond what your
   institution asks you to.

If you don't have these yet, ask your site administrator - the full setup
those two things depend on is covered in this plugin's admin-facing guides,
not something you need to do yourself.

## 1. Add the block

1. Go to the course you want the activities pulled **into** (the
   destination course).
2. Turn editing on.
3. Use your theme's "Add a block" option (in Moodle's default layout, this
   is usually in the course's block drawer) and choose **Course Sync**.

The block appears showing "Course Sync — not yet configured".

## 2. Connect it to the other site

1. Open the block's settings (its gear/cog icon, or "Configure Course Sync
   block").
2. You'll see a **"Connect to a remote site"** section with numbered setup
   steps - those are instructions for whoever administers the *other* site,
   not for you, and can usually stay collapsed.
3. Fill in:
   - **Remote site URL** - the other site's full web address, from "Before
     you start" above.
   - **Token** - paste the token you were given.
   - Leave **"This is a development/testing connection (allow HTTP)"**
     unticked - that's only for testing against a private/local site, never
     for a real connection between two real sites.
4. Click **Save changes**.

The block now shows either:

- **"Connected to [site name]"** - it worked, or
- An error message explaining what went wrong (a bad token, an unreachable
  address, etc.) - double-check what you entered and try again. If it keeps
  failing, that usually means something on the *other* site's end needs
  attention (their web services aren't enabled, or the token is wrong or
  expired) - ask whoever administers that site.

## 3. Map a course

Still in the block's settings, once connected:

1. Find **"Remote course ID or shortname"**.
2. Enter the course you want to pull activities *from*, on the other site -
   either its numeric ID or its shortname (ask whoever administers that
   site if you're not sure which course this is).
3. Save changes again.

The block now shows **"Mapped to [course name]"**, and lists any activities
on that remote course that are new or changed since the last sync (the
first time, that means everything).

## 4. Sync now

Once connected and mapped, the block shows a **"Sync now"** link. Click it.

You'll see a summary like:

> **2 created, 1 flagged as conflicts, 0 not yet supported**

- **Created**: pulled in successfully - go back to your course page and
  they'll be there.
- **Flagged as conflicts**: an activity already exists in your course that
  this would have collided with, so it was **left alone** - nothing was
  overwritten or duplicated. See "Reading conflicts" below.
- **Not yet supported**: an activity type this version of Course Sync
  doesn't know how to recreate yet. It's still listed so you know it's
  there, but it wasn't pulled. It'll be picked up automatically once
  support for that type is added in a future update - you don't need to do
  anything.

Nothing is deleted or changed on the **other** site - Course Sync only ever
reads from there and writes into your own course.

## 5. Reading sync history and conflicts

Click **"View sync history"** (always available, even if the connection
isn't currently working) to see every past sync for this block, most recent
first. Each one expands to show exactly what happened:

- **Created** - what was pulled in, and its activity type.
- **Flagged as conflicts** - what it collided with. This tells you the name
  and location of the existing activity in your course that has the same
  identifying number as the one being synced - usually because you (or an
  earlier sync) already created something there. Nothing is done
  automatically about this: if you want the remote version instead, you'd
  need to remove or rename the existing local activity yourself, then sync
  again.
- **Failed** - something went wrong for a specific item (rare) - the reason
  is shown alongside it.

The most recent sync's details are shown open automatically; older ones are
collapsed - click on one to expand it.

## What kinds of activities can be pulled in?

As of this version: **Page, URL, Label, File (Resource), and Forum**
(settings only - not its discussions or posts). Other types are detected
and shown in the preview so you know they exist, but aren't pulled yet.

One exception: a course's built-in **"Announcements" forum** is never
pulled, even though it's a forum - every course already has its own, so
syncing a remote one would leave you with two.

## Good to know

- Syncing again only looks for what's **new or changed** since your last
  sync - it won't re-pull or duplicate something already brought in.
- If your course already has an activity of the same type with the exact
  same name as one being pulled - whether it came from a course backup, a
  shared template, or you built it yourself - it's treated as already
  present and quietly left out of the pull. It isn't shown as a conflict,
  since nothing actually collided; it just isn't news to you.
- Large files attached to a Resource activity may occasionally fail to sync
  if they're very large - this is reported to you clearly as a failure for
  that item, not a silent gap.
- Only accounts with edit-teacher or manager-level permissions can add this
  block or trigger a sync, same as most other editing actions on a course.
