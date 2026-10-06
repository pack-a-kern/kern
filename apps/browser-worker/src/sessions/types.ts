export type SessionStatus =
  | "created"
  | "awaiting_challenge"
  | "authenticated"
  | "failed"
  | "destroyed";

export interface BrowserSession {
  readonly id: string;
  readonly status: SessionStatus;
  readonly createdAt: Date;
  readonly expiresAt: Date;
  readonly downloadsDir: string;
}
