# Course Radar — Moodle Report Plugin

[![Moodle Plugin CI](https://github.com/SergioComeron/moodle-report_courseradar/actions/workflows/ci.yml/badge.svg)](https://github.com/SergioComeron/moodle-report_courseradar/actions/workflows/ci.yml)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Moodle 4.5+](https://img.shields.io/badge/Moodle-4.5%2B-orange)](https://moodle.org)

A Moodle course report that gives teachers a comprehensive view of how students interact with every resource and activity in their course.

## Features

- **Summary dashboard** — total resources, total interactions, average engagement rate, and most-visited item at a glance.
- **Resource & activity table** — grouped by course section, showing per-item:
  - Total views and unique student visits
  - Color-coded coverage progress bar (red / yellow / green)
  - Date of last access
  - Expandable panel listing which students have (and haven't) viewed the item, with per-student view count and last-access timestamp.
- **Student engagement table** — one row per student showing:
  - Number of resources visited out of total
  - Coverage progress bar
  - Total views and last activity date
  - Expandable panel showing each resource as a color-coded badge (green = visited, grey = not visited).
- **Date range filter** — narrow the report to any period; defaults to the full course start date → today.
- **Role-aware** — only student interactions are counted; teachers and managers are excluded automatically.
- Languages: **English** and **Spanish** included.

## Requirements

| Component | Minimum version |
|-----------|----------------|
| Moodle    | 4.5 (2024100700) |
| PHP       | 8.1 |
| Log store | Standard log store enabled |

## Installation

### Via ZIP (recommended for production)

1. Download the latest release ZIP from the [Releases](../../releases) page.
2. In Moodle go to **Site administration → Plugins → Install plugins**.
3. Upload the ZIP and follow the on-screen steps.
4. Complete the database upgrade notification.

### Via Git (development)

```bash
cd /path/to/moodle/report
git clone https://github.com/SergioComeron/moodle-report_courseradar.git courseradar
```

Then visit **Site administration → Notifications** to run the installer.

## Usage

Once installed the report appears in every course under **Course administration → Reports → Course Radar**.

Teachers, editing teachers, and managers can access the report. Students cannot.

## Configuration

No site-level configuration is required. All filtering is done per-report-run using the date range selector at the top of the page.

## Contributing

Pull requests are welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) before submitting.

For bug reports and feature requests, open an [issue](../../issues).

## License

This plugin is released under the [GNU General Public License v3.0](LICENSE).

Moodle itself is also GPL v3. All derivative works must be distributed under the same licence.
