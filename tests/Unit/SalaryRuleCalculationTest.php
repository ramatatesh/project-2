<?php

namespace Tests\Unit;

use App\Models\SalaryRule;
use PHPUnit\Framework\TestCase;

/**
 * True unit test: SalaryRule::calculate() is pure math over the model's own attributes -
 * no database, no HTTP, no other classes involved. The model is only ever instantiated in
 * memory (never saved), which is what makes this a Unit test rather than a Feature test.
 */
class SalaryRuleCalculationTest extends TestCase
{
    //معادلة حساب الحسم/ الحافز من الراتب
    public function test_percent_based_rule_computes_a_share_of_the_daily_wage(): void
    {
        // Late-deduction rule: 15% of the daily wage, applied over 2 late days.
        $rule = new SalaryRule([
            'value_type' => 'percent',
            'value' => 15,
        ]);

        // dailyWage(1500) = 50, rate = 0.15 -> 50 * 0.15 * 2 = 15
        $this->assertEqualsWithDelta(15.0, $rule->calculate(1500, 2), 0.0001);
    }

    public function test_fixed_based_rule_ignores_the_percent_conversion(): void
    {
        // Fixed rule: the raw "value" is the rate itself, not divided by 100.
        $rule = new SalaryRule([
            'value_type' => 'fixed',
            'value' => 0.45,
        ]);

        // dailyWage(1000) = 33.333..., rate = 0.45 -> 33.333 * 0.45 * 1 = 15
        $this->assertEqualsWithDelta(15.0, $rule->calculate(1000, 1), 0.0001);
    }

    public function test_percentage_spelling_is_also_treated_as_a_percent_rule(): void
    {
        $rule = new SalaryRule([
            'value_type' => 'percentage',
            'value' => 10,
        ]);

        $this->assertTrue($rule->isPercent());
    }

    public function test_zero_units_always_yields_zero_regardless_of_rate(): void
    {
        $rule = new SalaryRule([
            'value_type' => 'percent',
            'value' => 50,
        ]);

        $this->assertSame(0.0, $rule->calculate(2000, 0));
    }
}
