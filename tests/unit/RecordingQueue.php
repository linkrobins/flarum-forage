<?php

/*
 * This file is part of linkrobins/flarum-forage.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Forage\Tests\unit;

use Illuminate\Contracts\Queue\Queue;

/**
 * A queue that runs nothing and remembers everything, so a test can assert on
 * what was queued rather than on what a job did.
 */
class RecordingQueue implements Queue
{
    /** @var list<mixed> */
    public array $jobs = [];

    public function size($queue = null)
    {
        return count($this->jobs);
    }

    public function pendingSize($queue = null)
    {
        return count($this->jobs);
    }

    public function delayedSize($queue = null)
    {
        return 0;
    }

    public function reservedSize($queue = null)
    {
        return 0;
    }

    public function creationTimeOfOldestPendingJob($queue = null)
    {
        return null;
    }

    public function push($job, $data = '', $queue = null)
    {
        $this->jobs[] = $job;

        return null;
    }

    public function pushOn($queue, $job, $data = '')
    {
        return $this->push($job, $data, $queue);
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $this->jobs[] = $payload;

        return null;
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->push($job, $data, $queue);
    }

    public function laterOn($queue, $delay, $job, $data = '')
    {
        return $this->push($job, $data, $queue);
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        foreach ($jobs as $job) {
            $this->push($job, $data, $queue);
        }

        return null;
    }

    public function pop($queue = null)
    {
        return array_shift($this->jobs);
    }

    public function getConnectionName()
    {
        return 'recording';
    }

    public function setConnectionName($name)
    {
        return $this;
    }
}
