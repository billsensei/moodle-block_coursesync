<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * One remote activity and what a sync run decided to do about it.
 *
 * @package    block_coursesync
 * @copyright  2026 block_coursesync contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_coursesync\local\sync;

/**
 * The planner's verdict on a single remote activity.
 */
class plan_item {
    /** @var string Not held locally, so pull it for the first time. */
    public const ACTION_NEW = 'new';

    /** @var string Changed remotely, and safe to replace the local copy. */
    public const ACTION_UPDATE = 'update';

    /** @var string Nothing changed remotely since the last pull. */
    public const ACTION_UNCHANGED = 'unchanged';

    /** @var string Needs a person to look at it; nothing is touched. */
    public const ACTION_CONFLICT = 'conflict';

    /** @var string Cannot be pulled at all, for example a module that has no backup support. */
    public const ACTION_SKIPPED = 'skipped';

    /** @var string Both sides changed since the last pull. */
    public const CONFLICT_BOTH_CHANGED = 'bothchanged';

    /** @var string An activity this block did not create already claims the name or id number. */
    public const CONFLICT_NAME_COLLISION = 'namecollision';

    /** @var string The module type cannot be backed up. */
    public const SKIP_NO_BACKUP_SUPPORT = 'nobackupsupport';

    /**
     * Constructor.
     *
     * @param int $remotecmid Course module id on the remote site.
     * @param string $modname Activity module type.
     * @param string $name Activity name on the remote site.
     * @param int $sectionnum Section the activity sits in remotely.
     * @param string $remotesignal The remote change signal seen in this run.
     * @param string $remotesignalmethod How that signal was derived.
     * @param string $action One of the ACTION_* constants.
     * @param int|null $localcmid The local copy, where one is known.
     * @param string|null $reason Why this item is in conflict or was skipped.
     */
    public function __construct(
        /** @var int Course module id on the remote site. */
        public readonly int $remotecmid,
        /** @var string Activity module type. */
        public readonly string $modname,
        /** @var string Activity name on the remote site. */
        public readonly string $name,
        /** @var int Section the activity sits in remotely. */
        public readonly int $sectionnum,
        /** @var string The remote change signal seen in this run. */
        public readonly string $remotesignal,
        /** @var string How that signal was derived. */
        public readonly string $remotesignalmethod,
        /** @var string One of the ACTION_* constants. */
        public readonly string $action,
        /** @var int|null The local copy, where one is known. */
        public readonly ?int $localcmid = null,
        /** @var string|null Why this item is in conflict or was skipped. */
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Whether acting on this item means fetching and restoring a backup.
     *
     * @return bool
     */
    public function requires_transfer(): bool {
        return $this->action === self::ACTION_NEW || $this->action === self::ACTION_UPDATE;
    }
}
