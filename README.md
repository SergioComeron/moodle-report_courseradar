# Course Radar — Moodle Report Plugin

[![Moodle Plugin CI](https://github.com/SergioComeron/moodle-report_courseradar/actions/workflows/ci.yml/badge.svg)](https://github.com/SergioComeron/moodle-report_courseradar/actions/workflows/ci.yml)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![Moodle 4.5+](https://img.shields.io/badge/Moodle-4.5%2B-orange)](https://moodle.org)

A Moodle course report that gives teachers a comprehensive view of how students interact with every resource and activity in their course. The report is organised into three tabs to reduce scrolling and keep related information together.

## Features

### Overview tab

- **KPI cards** — total resources, total interactions, course visits, and average engagement, each with a week-over-week trend indicator (↑/↓ %) comparing the current calendar week to the previous one.
- **At-risk students panel** — lists students with no interactions or very low activity (< 30 % resources visited). Includes a one-click messaging form to send a Moodle internal message to all at-risk students at once.
- **Engagement distribution chart** — horizontal bar chart showing how many students fall in each coverage quartile (0–24 %, 25–49 %, 50–74 %, 75–100 %).
- **Least visited resources** — top resources with the lowest student coverage, filterable by resource type.
- **Activity over time** — line chart of daily (or weekly, when the period exceeds 90 days) student interactions.
- **Activity pattern heatmap** — interaction counts grouped by day of week and 4-hour time slot, revealing when students study most.

### Resources tab

- **Interactions by resource type** — bar chart normalised to the most-viewed type.
- **Resources & Activities table** — grouped by course section, showing per item:
  - Total views, unique students, colour-coded coverage progress bar, last access date, and (when enabled) completion count.
  - Expandable panel listing which students have and haven't viewed the item, with per-student view count and last-access timestamp.
  - Filters by resource type and a toggle to show/hide hidden activities.

### Students tab

- **Student Engagement table** — one sortable row per student with:
  - Resources visited (X / total), coverage bar, total views, course visits, last course visit, last activity, days inactive, completion progress (X / tracked, when enabled), and a composite engagement score (0–100).
  - Click any row to expand a compact icon-grid showing visited (blue/green) and unvisited (grey) activities per section. View count and completion status appear on hover.
  - Weekly activity sparkline with a trend indicator vs the previous week.
  - Search field (shown when > 5 students) to filter rows by name.
  - Messaging form to contact all at-risk students without leaving the report.

### General

- **Date range filter** — narrows all data to any period; defaults to course start date → today.
- **Persistent UI state** — active tab, type filters, and the show-hidden toggle are saved per course in `localStorage` and restored on the next visit.
- **Role-aware** — only student interactions are counted; teachers and managers are excluded automatically.
- **Languages** — English and Spanish included.

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

Pull requests are welcome. For bug reports and feature requests, open an [issue](../../issues).

## License

This plugin is released under the [GNU General Public License v3.0](LICENSE).

Moodle itself is also GPL v3. All derivative works must be distributed under the same licence.
