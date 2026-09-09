export type SpvSessionStatus =
  | "created"
  | "awaiting_challenge"
  | "authenticated"
  | "failed"
  | "destroyed";

export interface SpvSession {
  readonly id: string;
  readonly status: SpvSessionStatus;
  readonly createdAt: Date;
  readonly expiresAt: Date;
  readonly downloadsDir: string;
}
