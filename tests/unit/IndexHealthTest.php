<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use LinkRobins\Forage\IndexHealth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * When the forum should speak up about its index, and — just as important —
 * when it should keep quiet. A warning that cries wolf gets ignored, and then
 * it is worth nothing on the day it is right.
 */
class IndexHealthTest extends TestCase
{
    protected function health(int $expected): FixedExpectationHealth
    {
        return new FixedExpectationHealth($expected);
    }

    /**
     * The case this exists for. A valid key in front of an empty index means
     * every search on the forum finds nothing, and everything else on the
     * settings page still reads "Connected".
     *
     * @test
     */
    #[Test]
    public function an_empty_index_on_a_forum_with_posts_is_reported(): void
    {
        $this->assertEquals(IndexHealth::EMPTY_INDEX, $this->health(473)->verdict(0));
    }

    /** @test */
    #[Test]
    public function a_full_index_is_fine(): void
    {
        $this->assertEquals(IndexHealth::OK, $this->health(473)->verdict(473));
    }

    /**
     * A brand-new forum has nothing to index, so an empty index is correct
     * rather than broken.
     *
     * @test
     */
    #[Test]
    public function an_empty_forum_is_not_missing_anything(): void
    {
        $this->assertEquals(IndexHealth::OK, $this->health(0)->verdict(0));
    }

    /**
     * Indexing is queued and applied asynchronously, so being slightly behind
     * is ordinary. Complaining about it would train an admin to ignore this.
     *
     * @test
     */
    #[Test]
    public function being_a_little_behind_is_not_worth_mentioning(): void
    {
        // 10% of 473 is ~47, so 40 behind is within the allowance.
        $this->assertEquals(IndexHealth::OK, $this->health(473)->verdict(433));
    }

    /** @test */
    #[Test]
    public function being_a_long_way_behind_is(): void
    {
        $this->assertEquals(IndexHealth::SHORT, $this->health(473)->verdict(100));
    }

    /**
     * On a small forum a proportion is uselessly tight — 10% of eight posts is
     * less than one — so a flat allowance takes over and a couple of posts in
     * flight raise nothing.
     *
     * @test
     */
    #[Test]
    public function a_small_forum_gets_a_flat_allowance_instead_of_a_proportion(): void
    {
        $this->assertEquals(IndexHealth::OK, $this->health(8)->verdict(2));
        $this->assertEquals(IndexHealth::EMPTY_INDEX, $this->health(8)->verdict(0));
    }

    /**
     * At the plan's limit the index is short on purpose and the banner already
     * says so in its own words. Saying it again as a fault would be wrong.
     *
     * @test
     */
    #[Test]
    public function a_forum_at_its_plan_limit_is_not_reported_as_short(): void
    {
        $this->assertEquals(IndexHealth::OK, $this->health(90000)->verdict(50000, 50000));
    }

    /**
     * No count means no opinion. The key issued to a forum may not be allowed
     * to read the index's statistics at all, and guessing from that would put
     * a warning on a forum that is working perfectly.
     *
     * @test
     */
    #[Test]
    public function no_count_means_no_verdict(): void
    {
        $this->assertEquals(IndexHealth::UNKNOWN, $this->health(473)->verdict(null));
    }
}
