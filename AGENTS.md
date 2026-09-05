# Project Guidelines

## Project Context

- This project is an ERP and inventory management system built with Laravel and Filament.
- The system uses AED as its currency.
- The business operates one physical warehouse.
- Products are mainly laptops and electronics.
- Inventory may be virtually assigned to employees by brand.
- Products are tracked by title and SKU, not by serial number.
- Average cost must be calculated from completed stock purchases.
- A product's `cost_price` must not automatically become its average cost.
- Returns marked `OK` must return to available inventory.
- Damaged returns must be moved to the Damaged/Safe-T section.
- Gross profit, average cost, and stock value are owner-only information.
- Every employee must have a separate login.
- Important actions must be recorded in activity logs.

## Development Rules

1. Follow Laravel and Filament conventions.
2. Use Eloquent relationships.
3. Never edit an existing migration after it has been used.
4. Create new migrations for schema changes.
5. Never run `migrate:fresh`, `db:wipe`, or other destructive database commands.
6. Never modify the `.env` file without approval.
7. Never expose passwords, API keys, or credentials.
8. Do not install packages without approval.
9. Add validation to every form.
10. Use database transactions for inventory operations.
11. Prevent negative stock.
12. Add authorization for financial and owner-only information.
13. Run Laravel Pint after code changes.
14. Run relevant tests after code changes.
15. Explain database changes before implementing them.
16. Work on one module at a time.
17. Do not deploy or push directly to the `main` branch.
