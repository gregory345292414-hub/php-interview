# Org Chart Endpoint

A single plain-PHP endpoint that, given an employee and an action, returns their
manager, their direct reports, or their full reporting tree with levels. Output is JSON.

## Running it

Requires PHP 8+ with the SQLite PDO driver (included by default).

```sh
sqlite3 employees.db < sqlite.sql   # create the database (one time)
php -S localhost:8000               # serve from this folder
```

## Usage

Pick an action (`manager`, `reports`, `tree`) and an employee, by `id` or by full `name`:

| Example | URL |
|---|---|
| Manager of Ben Walsh | `http://localhost:8000/?action=manager&name=Ben Walsh` |
| Direct reports of David Okafor | `http://localhost:8000/?action=reports&id=1002` |
| Everyone below Tom Becker | `http://localhost:8000/?action=tree&name=Tom Becker` |

The `tree` action returns a nested structure mirroring the org chart: each
person has a `reports` array holding the people directly under them, plus a
`level` (1 for direct reports, 2 for their reports, and so on). The traversal
is done in SQL with a recursive CTE; PHP only folds the flat rows into the
nested shape.

Errors return JSON with a matching HTTP status: 400 for a bad/missing parameter,
404 for an unknown employee.

## Design notes

- **Recursive CTE** for the reporting tree (`WITH RECURSIVE`), supported by both
  SQLite and MySQL 8, so the same query works on either database option.
- **Prepared statements** for every query that takes input.
- **Inactive employees are included** in all results, with their `active` flag
  visible in the output. The spec doesn't say to filter them, and the data has an
  inactive manager (Henry Adams) with active reports — filtering inactive rows out
  of the tree would silently hide his two interns, so inclusion felt like the
  safer default. Easy to change if the requirement is the opposite.
- The CEO's `manager` is returned as `null` rather than an error.
