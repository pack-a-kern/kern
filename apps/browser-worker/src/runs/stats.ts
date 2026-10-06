/**
 * What this worker has been doing, for a screen that asks.
 *
 * Counters and a duration sample, held in memory and lost on restart — this answers "is it working
 * and what is it doing now", not "what happened last Tuesday". Anything that has to survive a
 * restart belongs to whoever is calling, who has a database.
 *
 * No URLs and no playbook contents: a stats endpoint is the easiest place to leak by accident,
 * because nobody thinks of it as handling data. Names and counts only.
 */

const SAMPLE_SIZE = 200;

export class Stats {
  private readonly startedAt = Date.now();
  private runs = 0;
  private readonly outcomes = new Map<string, number>();
  private readonly durations: number[] = [];
  private failures = 0;
  private browsers = 0;

  public recordBrowser(): void {
    this.browsers += 1;
  }

  public recordRun(outcome: string, durationMs: number): void {
    this.runs += 1;
    this.outcomes.set(outcome, (this.outcomes.get(outcome) ?? 0) + 1);
    this.durations.push(durationMs);

    // A ring of the most recent, so a long-lived worker reports what it is doing now rather than an
    // average flattened by everything it ever did.
    if (this.durations.length > SAMPLE_SIZE) {
      this.durations.shift();
    }
  }

  public recordFailure(): void {
    this.failures += 1;
  }

  public snapshot(sessions: number, queued: number, running: number): Record<string, unknown> {
    const sorted = [...this.durations].sort((a, b) => a - b);

    return {
      uptime_s: Math.round((Date.now() - this.startedAt) / 1000),
      runs: this.runs,
      failures: this.failures,
      outcomes: Object.fromEntries(this.outcomes),
      duration_ms: {
        p50: percentile(sorted, 0.5),
        p95: percentile(sorted, 0.95),
      },
      browsers_launched: this.browsers,
      sessions_open: sessions,
      queue: { running, waiting: queued },
    };
  }
}

function percentile(sorted: readonly number[], fraction: number): number | null {
  if (sorted.length === 0) {
    return null;
  }

  return sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * fraction))] ?? null;
}
