# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel 12 application for a motorcycle rental platform. The project uses PHP 8.2+, Laravel Sail for Docker containerization, and Vite for frontend asset building with Tailwind CSS.

## Core Development Commands

### Running the Application
```bash
# Full development environment (server, queue, logs, and vite) - Recommended
composer run dev

# Individual services
php artisan serve          # Start development server
php artisan queue:listen   # Process queues
php artisan pail           # Watch logs in real-time
npm run dev                # Start Vite development server

# Laravel Sail (Docker alternative)
./vendor/bin/sail up       # Start all services
./vendor/bin/sail artisan  # Run artisan commands
```

### Database Operations
```bash
php artisan migrate              # Run migrations
php artisan migrate:fresh        # Reset and re-run all migrations
php artisan migrate:fresh --seed # Reset database with seeders
php artisan db:seed              # Run seeders
php artisan tinker               # Interactive REPL for database queries
```

### Testing
```bash
# Run all tests
composer test
php artisan test

# Run specific test suites
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature

# Run a specific test file
php artisan test tests/Feature/ExampleTest.php

# Run tests with coverage
php artisan test --coverage
```

### Code Quality
```bash
# Format code with Laravel Pint
./vendor/bin/pint

# Format specific file or directory
./vendor/bin/pint app/Models
./vendor/bin/pint app/Models/User.php
```

### Building for Production
```bash
npm run build              # Build frontend assets
composer install --no-dev  # Install production dependencies
php artisan config:cache   # Cache configuration
php artisan route:cache    # Cache routes
php artisan view:cache     # Cache views
```

## Application Architecture

### Domain Models and Relationships

The application centers around a motorcycle rental system with these key entities:

- **User**: Extended authentication model with rental-specific fields (CPF, RG, CNH, credit limit). Has roles: admin, employee, customer
- **Motorcycle**: Vehicle inventory with status tracking (available, rented, maintenance)
- **Rental**: Core business entity linking users to motorcycles with pricing and status management
- **Payment**: Financial transactions for rentals and fees
- **MaintenanceRecord**: Service history tracking for motorcycles

Key relationships:
- User has many Rentals and Payments
- Motorcycle has many Rentals and MaintenanceRecords
- Rental belongs to User and Motorcycle, has many Payments
- Payment belongs to User and optionally to Rental

### Database Structure

Uses SQLite for local development and testing (in-memory for tests). Migrations establish:
- User enhancements for Brazilian documentation (CPF, RG, CNH)
- Motorcycle inventory with detailed specifications
- Rental lifecycle with pricing tiers
- Payment processing with multiple types
- Maintenance tracking system

### Controllers and Routing

Resource controllers handle CRUD operations:
- `MotorcycleController`: Vehicle management
- `RentalController`: Rental operations
- `PaymentController`: Payment processing
- `MaintenanceRecordController`: Service tracking

## Testing Strategy

PHPUnit configuration uses:
- SQLite in-memory database for fast test execution
- Separate Unit and Feature test suites
- Factory classes for test data generation
- Database transactions for test isolation

## Frontend Stack

- Vite for asset bundling and HMR
- Tailwind CSS v4 for styling
- Laravel Vite Plugin for integration
- Axios for HTTP requests

## Key Laravel Features Used

- Eloquent ORM with relationship definitions
- Model factories and seeders for test data
- Form request validation
- Resource controllers
- Attribute casting for data types (dates, arrays, decimals)
- Laravel Pail for log monitoring
- Laravel Sail for containerized development