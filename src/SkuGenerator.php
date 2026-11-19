<?php
namespace IkongDev\SkuGenerator;

class SkuGenerator
{
    private array $components = [];

    // -------------------------------------------------------------------------
    // Static factory
    // -------------------------------------------------------------------------

    public static function make(string $title, string $format = 'default'): self
    {
        $instance = new self();
        $instance->addTitle($title, $format)->separator()->case();
        return $instance;
    }

    // -------------------------------------------------------------------------
    // Repeatable options  (can be called multiple times; values are appended)
    // -------------------------------------------------------------------------

    /**
     * Add a title segment, optionally transformed by a format before storing.
     *
     * Formats:
     *   default          -> "Running Shoes"              (untouched)
     *   vowels           -> "ui oe"                      (only vowels kept)
     *   consonants       -> "Rnnng Shs"                  (only consonants kept)
     *   uppercase        -> "RUNNING SHOES"              (all caps before global case)
     *   start            -> "R"                          (first character only)
     *   end              -> "s"                          (last character only)
     *   startword        -> "Running"                    (first word only)
     *   endword          -> "Shoes"                      (last word only)
     *   ascii            -> "82 117 110 110 105 110 103" (each char as ASCII code)
     *   jumble           -> "ounhgRSsnieen"              (characters shuffled)
     *   randomize        -> alias of jumble
     *   reversed         -> "seohS gninuR"               (string reversed)
     *   palindrome       -> "Running ShoesseohS gninuR"  (string + reverse appended)
     *   noempty          -> "RunningShoes"               (all whitespace stripped)
     */
    public function addTitle(string $title, string $format = 'default'): self
    {
        $title = $this->applyFormat($title, $format);
        return $this->setMultiple('titles', $title);
    }

    /** Add a prefix that is prepended before the title block. */
    public function addPrefix(string $prefix, string $format = 'default'): self
    {
        $prefix = $this->applyFormat($prefix, $format);
        return $this->setMultiple('prefixes', $prefix);
    }

    /** Add a suffix that is appended after the title block (and random parts). */
    public function addSuffix(string $suffix, string $format = 'default'): self
    {
        $suffix = $this->applyFormat($suffix, $format);
        return $this->setMultiple('suffixes', $suffix);
    }

    // -------------------------------------------------------------------------
    // Replaceable options  (last call wins)
    // -------------------------------------------------------------------------

    /**
     * Character used to join SKU segments.
     * Default: '/'  — common in e-commerce (e.g. SHOES/RED/42).
     */
    public function separator(string $char = '-'): self
    {
        return $this->replace('separator', $char);
    }

    /**
     * Case transformation applied to every segment.
     *
     * Supported values:
     *   uppercase  -> SHOES/RED/42
     *   lowercase  -> shoes/red/42
     *   ucwords    -> Shoes/Red/42
     *   sentence   -> Shoes/red/42  (first letter of whole SKU only)
     *   mixed      -> left as-is
     */
    public function case (string $case = 'uppercase'): self
    {
        $validCases = ['uppercase', 'lowercase', 'mixed', 'ucwords', 'sentence'];

        if (! in_array(strtolower($case), $validCases, true)) {
            throw new \InvalidArgumentException(
                "Invalid case '{$case}'. Allowed: " . implode(', ', $validCases)
            );
        }

        return $this->replace('case', strtolower($case));
    }

    /**
     * Limit the final SKU to $maxLength characters (truncated, not an error).
     */
    public function maxLength(int $maxLength): self
    {
        return $this->replace('maxLength', max(1, $maxLength));
    }

    /**
     * When enabled, the separator is forced to '-' and the SKU is URL-safe.
     * Overrides whatever separator() was set to.
     */
    public function toSlug(): self
    {
        return $this->replace('toSlug', true);
    }

    /**
     * Append the current date (or a custom date) as a segment.
     *
     * @param string|null $date    Any strtotime()-compatible string, or null for today.
     * @param string      $format  PHP date() format. Default: Ymd -> 20260522
     */
    public function withDate(?string $date = null, string $format = 'Ymd'): self
    {
        $timestamp = $date ? strtotime($date) : time();
        if ($timestamp === false) {
            throw new \InvalidArgumentException("Invalid date string: '{$date}'");
        }
        return $this->replace('date', date($format, $timestamp));
    }

    /**
     * Cap how many times uniqueInModel() will retry on collision.
     * Default: 100.
     */
    public function repeatLimit(int $limit = 100): self
    {
        return $this->replace('repeatLimit', max(1, $limit));
    }

    // -------------------------------------------------------------------------
    // Single-use options  (calling the same method twice throws)
    // -------------------------------------------------------------------------

    /** Append a random integer of $length digits.          e.g. SKU/43217  */
    public function hasNumber(int $length = 5): self
    {
        return $this->setOnce('hasNumber', max(1, $length));
    }

    /** Prepend a random integer of $length digits.         e.g. 4321/SKU   */
    public function hasPrefixNumber(int $length = 5): self
    {
        return $this->setOnce('hasPrefixNumber', max(1, $length));
    }

    /** Append a random alphabetic string of $length chars.  e.g. SKU/XKZQ  */
    public function hasRandomString(int $length = 5): self
    {
        return $this->setOnce('hasRandomString', max(1, $length));
    }

    /** Prepend a random alphabetic string of $length chars. e.g. XKZ/SKU   */
    public function hasRandomPrefixString(int $length = 5): self
    {
        return $this->setOnce('hasRandomPrefixString', max(1, $length));
    }

    /** Append a random alphanumeric string of $length chars. e.g. SKU/A3K9PZ */
    public function hasAlphanumeric(int $length = 6): self
    {
        return $this->setOnce('hasAlphanumeric', max(1, $length));
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /**
     * Keep regenerating until the SKU is unique in the given model's table.
     *
     * Collision strategies (set via magic __call):
     *   ->throwIfDataExist()                  Throw immediately on first collision. Good for testing.
     *   ->incrementIfDataExist()              Append incrementing number on collision: SKU/1, SKU/2 …
     *   ->randomNumberIfDataExist($range)     Append a random number within $range on collision.
     *   ->randomLetterIfDataExist($pool)      Append random letters from the given pool on collision.
     *
     * Behaviour:
     *   - Attempt 1 always tries the clean base SKU with no decoration.
     *   - Only if a collision is detected does the strategy kick in from attempt 2 onward.
     *   - generate() is called fresh every attempt so random components vary each retry.
     *
     * @param  string  $modelClass     Eloquent model class string (e.g. Product::class).
     * @param  string  $column         Column to check. Default: 'sku'.
     * @param  mixed   $ignored        Value to ignore (e.g., current record's SKU to exclude from uniqueness check).
     * @param  string  $ignoredColumn  Column name for the ignored value. Default: 'id'.
     * @return string  A SKU not yet present in the table.
     *
     * @throws \InvalidArgumentException  If $modelClass is not an Eloquent model.
     * @throws \LogicException            If no random component and no collision strategy is set.
     * @throws \RuntimeException          If strategy is 'throw' and SKU already exists,
     *                                    or if repeatLimit is exhausted.
     */
    public function uniqueInModel(string $modelClass, string $column = 'sku', $ignored = null, string $ignoredColumn = 'id'): string
    {
        if (! is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)) {
            throw new \InvalidArgumentException(
                "{$modelClass} must be an Eloquent Model class string."
            );
        }

        $strategy = $this->components['collisionStrategy'] ?? null;
        $limit    = $this->components['repeatLimit'] ?? 100;

        $randomKeys = [
            'hasNumber', 'hasPrefixNumber',
            'hasRandomString', 'hasRandomPrefixString', 'hasAlphanumeric',
        ];
        $hasRandom = count(array_filter($randomKeys, fn($k) => isset($this->components[$k]))) > 0;

        if (! $hasRandom && $strategy === null) {
            throw new \LogicException(
                'uniqueInModel() requires at least one random component ' .
                '(hasNumber, hasRandomString, hasAlphanumeric, etc.) ' .
                'or a collision strategy (e.g. ->incrementIfDataExist()).'
            );
        }

        $sep = ($this->components['toSlug'] ?? false)
            ? '-'
            : ($this->components['separator'] ?? '/');

        for ($attempt = 1; $attempt <= $limit; $attempt++) {
            // Always regenerate — random components produce a different value each attempt.
            $baseSku = $this->generate();

            // Attempt 1: always try the clean SKU first, no strategy decoration yet.
            // Attempt 2+: a collision already happened, so apply the strategy now.
            $sku = $attempt === 1
                ? $baseSku
                : $this->decorateOnCollision($baseSku, $strategy, $sep, $attempt);

            // Build query to check uniqueness
            $query = $modelClass::where($column, $sku);

            // If we have an ignored value, exclude that record from the check
            if ($ignored !== null) {
                $query->where($ignoredColumn, '!=', $ignored);
            }

            if (! $query->exists()) {
                return $sku;
            }

            // 'throw' — blow up immediately, no retries
            if ($strategy !== null && $strategy['name'] === 'throw') {
                throw new \RuntimeException(
                    "SKU '{$sku}' already exists in the database. " .
                    "(throwIfDataExist is active — no retries.)"
                );
            }
        }

        throw new \RuntimeException(
            "Could not generate a unique SKU after {$limit} attempts. " .
            'Increase the random segment length or repeatLimit().'
        );
    }

    /**
     * Build and return the final SKU string.
     */
    public function generate(): string
    {
        $sep = ($this->components['toSlug'] ?? false)
            ? '-'
            : ($this->components['separator'] ?? '/');

        $case = $this->components['case'] ?? 'uppercase';

        $parts = [];

        // 1. Optional numeric prefix
        if (isset($this->components['hasPrefixNumber'])) {
            $parts[] = $this->randomNumber($this->components['hasPrefixNumber']);
        }

        // 2. Optional random-string prefix
        if (isset($this->components['hasRandomPrefixString'])) {
            $parts[] = $this->randomAlpha($this->components['hasRandomPrefixString']);
        }

        // 3. User-defined prefixes
        foreach ($this->components['prefixes'] ?? [] as $prefix) {
            $parts[] = $prefix;
        }

        // 4. Title segments
        foreach ($this->components['titles'] ?? [] as $title) {
            $clean   = preg_replace('/[^\w\s\-]/', '', $title);
            $clean   = preg_replace('/\s+/', $sep, trim($clean));
            $parts[] = $clean;
        }

        // 5. User-defined suffixes
        foreach ($this->components['suffixes'] ?? [] as $suffix) {
            $parts[] = $suffix;
        }

        // 6. Date segment
        if (isset($this->components['date'])) {
            $parts[] = $this->components['date'];
        }

        // 7. Random suffix
        if (isset($this->components['hasAlphanumeric'])) {
            $parts[] = $this->randomAlphanumeric($this->components['hasAlphanumeric']);
        } elseif (isset($this->components['hasRandomString'])) {
            $parts[] = $this->randomAlpha($this->components['hasRandomString']);
        }

        if (isset($this->components['hasNumber'])) {
            $parts[] = $this->randomNumber($this->components['hasNumber']);
        }

        $sku = implode($sep, array_filter($parts, fn($p) => $p !== ''));
        $sku = $this->applyCase($sku, $case, $sep);

        if ($this->components['toSlug'] ?? false) {
            $sku = preg_replace('/[^A-Za-z0-9\-]/', '-', $sku);
            $sku = preg_replace('/-{2,}/', '-', $sku);
            $sku = trim($sku, '-');
        }

        if (isset($this->components['maxLength'])) {
            $sku = substr($sku, 0, $this->components['maxLength']);
        }

        return $sku;
    }

    /**
     * Return a human-readable config summary plus a live generated sample.
     */
    public function preview(): array
    {
        $strategy = $this->components['collisionStrategy'] ?? null;

        return [
            'titles'                => $this->components['titles'] ?? [],
            'prefixes'              => $this->components['prefixes'] ?? [],
            'suffixes'              => $this->components['suffixes'] ?? [],
            'separator'             => $this->components['separator'] ?? '/',
            'case'                  => $this->components['case'] ?? 'uppercase',
            'date'                  => $this->components['date'] ?? null,
            'hasNumber'             => $this->components['hasNumber'] ?? null,
            'hasPrefixNumber'       => $this->components['hasPrefixNumber'] ?? null,
            'hasRandomString'       => $this->components['hasRandomString'] ?? null,
            'hasRandomPrefixString' => $this->components['hasRandomPrefixString'] ?? null,
            'hasAlphanumeric'       => $this->components['hasAlphanumeric'] ?? null,
            'toSlug'                => $this->components['toSlug'] ?? false,
            'maxLength'             => $this->components['maxLength'] ?? null,
            'repeatLimit'           => $this->components['repeatLimit'] ?? 100,
            'collisionStrategy'     => $strategy
                ? $strategy['name'] . '(' . json_encode($strategy['config']) . ')'
                : null,
            'sample'                => $this->generate(),
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Decorate the base SKU with the collision strategy.
     * Only called from attempt 2 onward — only when a collision is confirmed.
     */
    private function decorateOnCollision(string $baseSku, ?array $strategy, string $sep, int $attempt): string
    {
        if ($strategy === null) {
            // No strategy — just return the freshly generated base (random components vary it)
            return $baseSku;
        }

        return match ($strategy['name']) {

            // throw: baseSku returned as-is; caller throws on collision
            'throw'        => $baseSku,

            // SHOES/1, SHOES/2, SHOES/3 … ($attempt - 1 so first decorated attempt = 1)
            'increment'    => $baseSku . $sep . ($attempt - 1),

            // SHOES/4821  (random int within range)
            'randomNumber' => $baseSku . $sep . random_int(1, max(1, (int) $strategy['config'])),

            // SHOES/XKZ  (random chars from the supplied pool)
            'randomLetter' => (function () use ($baseSku, $strategy, $sep): string {
                $pool   = $strategy['config'] ?? 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                $length = strlen($pool) > 1 ? (int) ceil(log(100, strlen($pool))) : 3;
                $length = max(1, min($length, 8));
                $result = '';
                for ($i = 0; $i < $length; $i++) {
                    $result .= $pool[random_int(0, strlen($pool) - 1)];
                }
                return $baseSku . $sep . $result;
            })(),

            default        => $baseSku,
        };
    }

    private function setMultiple(string $key, mixed $value): self
    {
        $this->components[$key][] = $value;
        return $this;
    }

    private function setOnce(string $key, mixed $value): self
    {
        if (array_key_exists($key, $this->components)) {
            throw new \RuntimeException("'{$key}' can only be set once.");
        }
        $this->components[$key] = $value;
        return $this;
    }

    private function replace(string $key, mixed $value): self
    {
        $this->components[$key] = $value;
        return $this;
    }

    /**
     * Apply a named format transformation to a raw title string.
     */
    private function applyFormat(string $label, string $format): string
    {
        $validFormats = [
            'default', 'vowels', 'consonants', 'uppercase',
            'start', 'end', 'startword', 'endword',
            'ascii', 'jumble', 'randomize', 'reversed',
            'palindrome', 'noempty',
        ];

        if (! in_array($format, $validFormats, true)) {
            throw new \InvalidArgumentException(
                "Invalid format '{$format}'. Allowed: " . implode(', ', $validFormats)
            );
        }

        return match ($format) {
            'default'    => $label,
            'vowels'     => preg_replace('/[^aeiouAEIOU\s]/', '', $label),
            'consonants' => preg_replace('/[aeiouAEIOU]/', '', $label),
            'noempty'    => preg_replace('/\s+/', '', $label),
            'uppercase'  => strtoupper($label),
            'start'      => substr($label, 0, 1),
            'end'        => substr(rtrim($label), -1),
            'startword'  => explode(' ', trim($label))[0],
            'endword'    => (function () use ($label): string {
                $words = preg_split('/\s+/', trim($label));
                return end($words);
            })(),
            'ascii'      => implode(' ', array_map('ord', str_split($label))),
            'jumble', 'randomize' => (function () use ($label): string {
                $chars = str_split($label);
                shuffle($chars);
                return implode('', $chars);
            })(),
            'reversed'   => strrev($label),
            'palindrome' => $label . strrev($label),
        };
    }

    private function applyCase(string $sku, string $case, string $sep): string
    {
        return match ($case) {
            'uppercase' => strtoupper($sku),
            'lowercase' => strtolower($sku),
            'ucwords'   => implode($sep, array_map('ucfirst', explode($sep, strtolower($sku)))),
            'sentence'  => ucfirst(strtolower($sku)),
            default     => $sku,
        };
    }

    private function randomNumber(int $length): string
    {
        $min = (int) str_pad('1', $length, '0');
        $max = (int) str_pad('9', $length, '9');
        return (string) random_int($min, $max);
    }

    private function randomAlpha(int $length): string
    {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, 25)];
        }
        return $result;
    }

    private function randomAlphanumeric(int $length): string
    {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Magic method
    // -------------------------------------------------------------------------

    /**
     * Handles two families of dynamic method calls:
     *
     * 1. Case shortcuts — call any valid case name directly:
     *       ->uppercase()  ->lowercase()  ->ucwords()  ->sentence()  ->mixed()
     *
     * 2. Collision strategies — any method ending in 'IfDataExist':
     *       ->throwIfDataExist()                  Throw immediately on first collision.
     *       ->incrementIfDataExist()              Append 1, 2, 3 … on each collision.
     *       ->randomNumberIfDataExist($range)     Append a random int within $range.
     *       ->randomLetterIfDataExist($pool)      Append random chars from $pool string.
     */
    public function __call(string $fnName, array $arguments): self
    {
        // ── 1. Case shortcuts ────────────────────────────────────────────────
        $validCases = ['uppercase', 'lowercase', 'mixed', 'ucwords', 'sentence'];

        if (in_array($fnName, $validCases, true)) {
            return $this->case($fnName);
        }

        // ── 2. IfDataExist collision strategies ──────────────────────────────
        if (str_ends_with($fnName, 'IfDataExist')) {
            $strategy        = lcfirst(str_replace('IfDataExist', '', $fnName));
            $validStrategies = ['throw', 'increment', 'randomNumber', 'randomLetter'];

            if (! in_array($strategy, $validStrategies, true)) {
                throw new \BadMethodCallException(
                    "Unknown collision strategy '{$strategy}'. " .
                    "Available: " . implode(', ', array_map(fn($s) => "{$s}IfDataExist()", $validStrategies))
                );
            }

            return $this->replace('collisionStrategy', [
                'name'   => $strategy,
                'config' => $arguments[0] ?? null,
            ]);
        }

        throw new \BadMethodCallException(
            "Method '{$fnName}()' does not exist on SkuGenerator."
        );
    }
}

/* ----------------EXAMPLE-USAGE--------------------
Here are the fully updated sample usages:

// ── 1. Simplest possible ─────────────────────────────────────────
$sku = SkuGenerator::make('Shoes')->generate();
// → SHOES

// ── 2. Multiple addTitle (color + size variant) ──────────────────
$sku = SkuGenerator::make('Polo Shirt')
    ->addTitle('Navy Blue')
    ->addTitle('XL')
    ->generate();
// → POLO/SHIRT/NAVY/BLUE/XL

// ── 3. Multiple addTitle (category > sub > variant) ──────────────
$sku = SkuGenerator::make('Footwear')
    ->addTitle('Boots')
    ->addTitle('Leather')
    ->addTitle('Brown')
    ->addTitle('EU43')
    ->generate();
// → FOOTWEAR/BOOTS/LEATHER/BROWN/EU43

// ── 4. Prefix + multiple titles + suffix ─────────────────────────
$sku = SkuGenerator::make('T-Shirt')
    ->addTitle('White')
    ->addTitle('Small')
    ->addPrefix('APP')
    ->addSuffix('PH')
    ->generate();
// → APP/T-SHIRT/WHITE/SMALL/PH

// ── 5. Multiple prefixes and suffixes ────────────────────────────
$sku = SkuGenerator::make('Moisturizer')
    ->addTitle('SPF50')
    ->addPrefix('BEAUTY')
    ->addPrefix('SKIN')
    ->addSuffix('50ML')
    ->addSuffix('PH')
    ->generate();
// → BEAUTY/SKIN/MOISTURIZER/SPF50/50ML/PH

// ── 6. Product variants in a loop ────────────────────────────────
$colors = ['Red', 'Blue', 'Green'];
$sizes  = ['S', 'M', 'L', 'XL'];

foreach ($colors as $color) {
    foreach ($sizes as $size) {
        $sku = SkuGenerator::make('Hoodie')
            ->addTitle($color)
            ->addTitle($size)
            ->addPrefix('APP')
            ->hasNumber(4)
            ->generate();
        // → APP/HOODIE/RED/S/4821
        // → APP/HOODIE/RED/M/3910  ...etc
    }
}

// ── 7. Custom separator with multiple titles ─────────────────────
$sku = SkuGenerator::make('Running Shoes')
    ->addTitle('Black')
    ->addTitle('Size 42')
    ->separator('-')
    ->generate();
// → RUNNING-SHOES-BLACK-SIZE-42

// ── 8. Unique in DB — basic (new product) ────────────────────────
$sku = SkuGenerator::make('Leather Boots')
    ->addTitle('Brown')
    ->addTitle('EU43')
    ->addPrefix('FOOT')
    ->hasAlphanumeric(6)
    ->uniqueInModel(Product::class);
// → FOOT/LEATHER/BOOTS/BROWN/EU43/X3KP9Z (guaranteed not in products.sku)

// ── 9. Unique in DB — updating existing product (ignore current) ─
$productId = 42;
$sku = SkuGenerator::make('Leather Boots')
    ->addTitle('Brown')
    ->addTitle('EU43')
    ->addPrefix('FOOT')
    ->hasAlphanumeric(6)
    ->uniqueInModel(Product::class, 'sku', $productId, 'id');
// Ignores product ID 42 when checking uniqueness
// → FOOT/LEATHER/BOOTS/BROWN/EU43/X3KP9Z

// ── 10. Unique in DB — custom column and ignore by different field ─
$currentSku = 'OLD-SKU-123';
$sku = SkuGenerator::make('Circuit Board')
    ->addTitle('Rev2')
    ->addPrefix('ELEC')
    ->hasNumber(6)
    ->uniqueInModel(Part::class, 'part_number', $currentSku, 'sku');
// Ignores records with sku = 'OLD-SKU-123' when checking part_number

// ── 11. Unique in DB — with collision strategy (increment) ────────
$sku = SkuGenerator::make('T-Shirt')
    ->addTitle('White')
    ->incrementIfDataExist()
    ->uniqueInModel(Product::class);
// If 'T-SHIRT/WHITE' exists, tries 'T-SHIRT/WHITE/1', '/2', etc.

// ── 12. Unique in DB — with random number collision strategy ──────
$sku = SkuGenerator::make('T-Shirt')
    ->addTitle('White')
    ->randomNumberIfDataExist(1000)  // Range 1-1000
    ->uniqueInModel(Product::class);
// If collision, appends random number: 'T-SHIRT/WHITE/482'

// ── 13. Unique in DB — with random letter collision strategy ──────
$sku = SkuGenerator::make('T-Shirt')
    ->addTitle('White')
    ->randomLetterIfDataExist('ABCDEFGHIJKLMNOPQRSTUVWXYZ')
    ->uniqueInModel(Product::class);
// If collision, appends random letters: 'T-SHIRT/WHITE/XKZ'

// ── 14. Unique in DB — throw exception on collision ───────────────
try {
    $sku = SkuGenerator::make('T-Shirt')
        ->addTitle('White')
        ->throwIfDataExist()
        ->uniqueInModel(Product::class);
} catch (RuntimeException $e) {
    // Handle duplicate SKU
}

// ── 15. Unique in DB — high-traffic table with custom attempts ────
$sku = SkuGenerator::make('Cotton T-Shirt')
    ->addTitle('White')
    ->addTitle('Medium')
    ->hasAlphanumeric(8)
    ->repeatLimit(500)
    ->uniqueInModel(Product::class, 'sku');
// → COTTON/T-SHIRT/WHITE/MEDIUM/A3KP9ZXQ

// ── 16. Timestamped batch with multiple titles ───────────────────
$sku = SkuGenerator::make('Circuit Board')
    ->addTitle('Rev3')
    ->addTitle('SMD')
    ->addPrefix('ELEC')
    ->withDate()
    ->hasNumber(4)
    ->generate();
// → ELEC/CIRCUIT/BOARD/REV3/SMD/20260522/7731

// ── 17. Custom date format ───────────────────────────────────────
$sku = SkuGenerator::make('Report')
    ->withDate('2024-01-15', 'Y-m-d')
    ->generate();
// → REPORT/2024-01-15

// ── 18. URL-friendly slug ────────────────────────────────────────
$sku = SkuGenerator::make('Running Shoes')
    ->addTitle('Red & Black')
    ->addTitle('Size 42')
    ->toSlug()
    ->case('lowercase')
    ->generate();
// → running-shoes-red-black-size-42

// ── 19. ucwords case with multiple titles ────────────────────────
$sku = SkuGenerator::make('Aviator Sunglasses')
    ->addTitle('Gold Frame')
    ->addTitle('UV400')
    ->addPrefix('Eye')
    ->separator('-')
    ->case('ucwords')
    ->generate();
// → Eye-Aviator-Sunglasses-Gold-Frame-Uv400

// ── 20. Sentence case (first letter only capitalized) ────────────
$sku = SkuGenerator::make('PREMIUM LEATHER BOOTS')
    ->case('sentence')
    ->generate();
// → Premium leather boots

// ── 21. Mixed case (no transformation) ───────────────────────────
$sku = SkuGenerator::make('Premium Leather Boots')
    ->case('mixed')
    ->generate();
// → Premium Leather Boots (exactly as input)

// ── 22. Short fixed-length label / barcode ───────────────────────
$sku = SkuGenerator::make('Ultraboost')
    ->addTitle('Running')
    ->addTitle('Shoes')
    ->hasAlphanumeric(4)
    ->maxLength(14)
    ->generate();
// → ULTRABOOST/RUN  (truncated to 14 chars)

// ── 23. Random prefix and suffix numbers ─────────────────────────
$sku = SkuGenerator::make('Backpack')
    ->hasPrefixNumber(3)     // 3-digit number at start
    ->hasNumber(4)           // 4-digit number at end
    ->generate();
// → 742/BACKPACK/3910

// ── 24. Random string prefixes and suffixes ──────────────────────
$sku = SkuGenerator::make('Laptop')
    ->hasRandomPrefixString(4)  // 4 random letters at start
    ->hasRandomString(3)        // 3 random letters at end
    ->generate();
// → XKZQ/LAPTOP/ABC

// ── 25. Multiple format transformations ──────────────────────────
$sku = SkuGenerator::make('Running Shoes')
    ->addTitle('Nike Air Max', 'consonants')    // Nk r Mx
    ->addTitle('Blue/Red', 'vowels')            // ue e
    ->addTitle('Size 42', 'noempty')            // Size42
    ->separator('-')
    ->generate();
// → RUNNING-SHOES/NK-R-MX/UE-E/SIZE42

// ── 26. ASCII code format for barcode generation ─────────────────
$sku = SkuGenerator::make('SKU')
    ->addTitle('ABC', 'ascii')
    ->separator('-')
    ->generate();
// → SKU/65-66-67

// ── 27. Palindrome format for validation codes ───────────────────
$sku = SkuGenerator::make('CODE')
    ->addTitle('ABC', 'palindrome')
    ->generate();
// → CODE/ABC/CBA

// ── 28. Title format combinations in real e-commerce ─────────────
$sku = SkuGenerator::make('Wireless Headphones')
    ->addTitle('Sony WH-1000XM4', 'consonants')  // Sny WH-1000XM4
    ->addTitle('Black', 'uppercase')              // BLACK
    ->addTitle('Noise Cancelling', 'startword')   // Noise
    ->separator('_')
    ->hasNumber(5)
    ->generate();
// → WIRELESS_HEADPHONES_SNY_WH-1000XM4_BLACK_NOISE_82743

// ── 29. Kitchen sink (all features combined) ─────────────────────
$sku = SkuGenerator::make('Leather Boots')
    ->addTitle('Brown')
    ->addTitle('EU43')
    ->addPrefix('FOOT')
    ->addPrefix('PREMIUM')
    ->addSuffix('PH')
    ->addSuffix('2024')
    ->separator('-')
    ->case('uppercase')
    ->withDate('now', 'Ymd')
    ->hasAlphanumeric(4)
    ->hasPrefixNumber(2)
    ->maxLength(50)
    ->uniqueInModel(Product::class, 'sku', $existingId, 'id');
// → 42-FOOT-PREMIUM-LEATHER-BOOTS-BROWN-EU43-PH-2024-20260522-7DXC

// ── 30. Preview / debug before saving ────────────────────────────
$preview = SkuGenerator::make('Hoodie')
    ->addTitle('Black')
    ->addTitle('Large')
    ->addPrefix('APP')
    ->hasNumber(4)
    ->withDate()
    ->preview();

// Returns array with all configuration and a live sample:
// [
//     'titles' => ['Black', 'Large'],
//     'prefixes' => ['APP'],
//     'suffixes' => [],
//     'separator' => '/',
//     'case' => 'uppercase',
//     'date' => '20260522',
//     'hasNumber' => 4,
//     'hasPrefixNumber' => null,
//     'hasRandomString' => null,
//     'hasRandomPrefixString' => null,
//     'hasAlphanumeric' => null,
//     'toSlug' => false,
//     'maxLength' => null,
//     'repeatLimit' => 100,
//     'collisionStrategy' => null,
//     'sample' => 'APP/HOODIE/BLACK/LARGE/20260522/3933'
// ]

// ── 31. Complete CRUD operations example ─────────────────────────
class ProductController
{
    public function store(Request $request)
    {
        $category = Classification::find($request->classification_id);

        $sku = SkuGenerator::make($request->name)
            ->addPrefix($category->name, 'consonants')
            ->separator('-')
            ->hasNumber(4)
            ->uniqueInModel(Product::class);

        $slug = SkuGenerator::make($request->name)
            ->lowercase()
            ->toSlug()
            ->incrementIfDataExist()
            ->uniqueInModel(Product::class);

        Product::create([
            'name' => $request->name,
            'sku' => $sku,
            'slug' => $slug,
            'classification_id' => $request->classification_id,
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $category = Classification::find($request->classification_id);

        $sku = SkuGenerator::make($request->name)
            ->addPrefix($category->name, 'consonants')
            ->separator('-')
            ->hasNumber(4)
            ->uniqueInModel(Product::class, 'sku', $product->id, 'id');

        $slug = SkuGenerator::make($request->name)
            ->lowercase()
            ->toSlug()
            ->incrementIfDataExist()
            ->uniqueInModel(Product::class, 'slug', $product->id, 'id');

        $product->update([
            'name' => $request->name,
            'sku' => $sku,
            'slug' => $slug,
            'classification_id' => $request->classification_id,
        ]);
    }
}

// ── 32. Bulk generation with unique constraints ──────────────────
$products = [
    ['name' => 'T-Shirt', 'color' => 'Red', 'size' => 'M'],
    ['name' => 'T-Shirt', 'color' => 'Red', 'size' => 'L'],
    ['name' => 'T-Shirt', 'color' => 'Blue', 'size' => 'M'],
];

foreach ($products as $product) {
    $sku = SkuGenerator::make($product['name'])
        ->addTitle($product['color'])
        ->addTitle($product['size'])
        ->hasAlphanumeric(4)
        ->uniqueInModel(ProductVariant::class, 'sku');
    // Each variant gets a unique SKU automatically
}
*/
