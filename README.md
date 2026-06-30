# SKU Generator

A configurable and fluent SKU generation package for Laravel.

SKU Generator helps developers generate clean, readable, and unique product identifiers using a fluent API. It supports customizable formatting, prefixes, suffixes, collision strategies, slug generation, case transformations, and database uniqueness validation.

Designed to be reusable across different Laravel applications while keeping the generation engine independent from the persistence layer.

---

## ✨ Features

- Fluent API
- Configurable SKU generation
- Multiple prefixes and suffixes
- Unlimited title segments
- Custom separators
- Random numbers, strings, and alphanumeric generators
- Date generation
- Case transformations
- Slug generation
- Maximum length limiter
- Database uniqueness validation
- Collision handling strategies
- Preview mode
- Extensible architecture

---

<!-- ## Installation

```bash
composer require your-vendor/sku-generator
```

Publish the configuration if available.

```bash
php artisan vendor:publish
```

--- -->

# Quick Start

Generate a simple SKU.

```php
$sku = SkuGenerator::make('Shoes')
    ->generate();

// SHOES
```

---

# Product Variants

```php
$sku = SkuGenerator::make('Polo Shirt')
    ->addTitle('Navy Blue')
    ->addTitle('XL')
    ->generate();

// POLO/SHIRT/NAVY/BLUE/XL
```

---

# Prefixes & Suffixes

```php
$sku = SkuGenerator::make('T-Shirt')
    ->addPrefix('APP')
    ->addTitle('White')
    ->addTitle('Small')
    ->addSuffix('PH')
    ->generate();

// APP/T-SHIRT/WHITE/SMALL/PH
```

---

# Custom Separator

```php
$sku = SkuGenerator::make('Running Shoes')
    ->addTitle('Black')
    ->addTitle('Size 42')
    ->separator('-')
    ->generate();

// RUNNING-SHOES-BLACK-SIZE-42
```

---

# Random Values

Generate random numeric suffix.

```php
$sku = SkuGenerator::make('Backpack')
    ->hasNumber(4)
    ->generate();

// BACKPACK/4821
```

Generate random alphanumeric.

```php
$sku = SkuGenerator::make('Laptop')
    ->hasAlphanumeric(6)
    ->generate();

// LAPTOP/X3KP9Z
```

Generate random prefix.

```php
$sku = SkuGenerator::make('Laptop')
    ->hasRandomPrefixString(4)
    ->generate();

// XKZQ/LAPTOP
```

---

# Date Support

```php
$sku = SkuGenerator::make('Circuit Board')
    ->withDate()
    ->hasNumber(4)
    ->generate();

// CIRCUIT/BOARD/20260522/7731
```

Custom format

```php
$sku = SkuGenerator::make('Report')
    ->withDate('2024-01-15', 'Y-m-d')
    ->generate();

// REPORT/2024-01-15
```

---

# Slug Generation

```php
$sku = SkuGenerator::make('Running Shoes')
    ->addTitle('Red & Black')
    ->toSlug()
    ->case('lowercase')
    ->generate();

// running-shoes-red-black
```

---

# Case Transformations

Uppercase

```php
->case('uppercase')
```

Lowercase

```php
->case('lowercase')
```

Sentence Case

```php
->case('sentence')
```

Title Case

```php
->case('ucwords')
```

Mixed

```php
->case('mixed')
```

---

# Database Uniqueness

Generate a SKU that is guaranteed to be unique within a Laravel model.

```php
$sku = SkuGenerator::make('Leather Boots')
    ->addTitle('Brown')
    ->addTitle('EU43')
    ->hasAlphanumeric(6)
    ->uniqueInModel(Product::class);
```

## How uniqueness works

By default, `uniqueInModel()` checks uniqueness using a **Laravel Eloquent Model**.

Example:

```php
Product::where('sku', $generatedSku)->exists();
```

This dependency is intentionally isolated from the SKU generation engine.

If your application uses another persistence layer, you only need to replace the uniqueness validation logic while keeping the generator itself unchanged.

Examples include:

- Doctrine ORM
- Raw SQL
- MongoDB
- External APIs
- Repository Pattern
- Other PHP Frameworks

The SKU generation engine is independent from the storage implementation.

---

# Collision Strategies

Increment existing value.

```php
->incrementIfDataExist()
```

Append random number.

```php
->randomNumberIfDataExist(1000)
```

Append random letters.

```php
->randomLetterIfDataExist()
```

Throw exception.

```php
->throwIfDataExist()
```

Retry limit.

```php
->repeatLimit(500)
```

---

# CRUD Example

```php
$sku = SkuGenerator::make($request->name)
    ->addPrefix($category->name)
    ->separator('-')
    ->hasNumber(4)
    ->uniqueInModel(Product::class);

Product::create([
    'name' => $request->name,
    'sku' => $sku,
]);
```

---

# Preview

Preview a generated SKU without saving.

```php
$preview = SkuGenerator::make('Hoodie')
    ->addTitle('Black')
    ->addPrefix('APP')
    ->withDate()
    ->preview();
```

Returns configuration together with a generated sample SKU.

---

# Design Philosophy

SKU Generator was designed around three principles:

- Fluent and expressive API
- Separation of concerns
- Extensibility

The package focuses solely on SKU generation. Database access is isolated to the uniqueness validation layer, making it easy to adapt for different frameworks or storage implementations.

---

# Motivation

Many applications repeatedly implement custom SKU generation logic.

This package was created to provide a reusable, configurable, and maintainable solution that can be shared across multiple Laravel projects instead of rewriting the same functionality.

---

# Future Plans

- Additional formatting strategies
- More collision resolution strategies
- Custom generators
- Framework adapters
- Improved configuration options

---

# License

This project is released for educational and portfolio purposes.

---

# Author

**Jericho Umayam**

Backend Developer • Laravel • PHP • REST API • Software Engineering
