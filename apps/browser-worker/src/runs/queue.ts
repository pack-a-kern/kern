/**
 * One place every run passes through, however many are allowed at once.
 *
 * The limit is one by default and that is not the point: a service that assumed one-at-a-time and
 * later needed fairness, backpressure and a visible queue depth would have to be rewritten around
 * this, while a queue whose limit is one is a number. Each run holds a browser and several hundred
 * megabytes, so the number stays small whatever it is.
 *
 * Rejects rather than queues without bound: a caller waiting behind two hundred pending runs has
 * already timed out, and holding their request open teaches them nothing. `QueueFullError` says so
 * while it is still true.
 */

export class QueueFullError extends Error {
  public constructor(public readonly depth: number) {
    super(`The worker is at capacity with ${String(depth)} runs waiting.`);
    this.name = "QueueFullError";
  }
}

export class RunQueue {
  private active = 0;
  private readonly waiting: (() => void)[] = [];

  public constructor(
    private readonly concurrency: number,
    private readonly maxWaiting: number,
  ) {}

  public get depth(): number {
    return this.waiting.length;
  }

  public get running(): number {
    return this.active;
  }

  public async run<T>(work: () => Promise<T>): Promise<T> {
    if (this.active >= this.concurrency) {
      if (this.waiting.length >= this.maxWaiting) {
        throw new QueueFullError(this.waiting.length);
      }

      await new Promise<void>((resolve) => this.waiting.push(resolve));
    }

    this.active += 1;

    try {
      return await work();
    } finally {
      this.active -= 1;
      // Released in the `finally` of the run that held the slot, so a run that threw does not take
      // the slot with it — which is how a worker ends up permanently at capacity with nothing on it.
      this.waiting.shift()?.();
    }
  }
}
