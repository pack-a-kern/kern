import type { AllowList } from "./types.js";

export class DestinationRefusedError extends Error {
  public constructor(public readonly url: string) {
    super(`The playbook does not allow navigating to ${url}`);
    this.name = "DestinationRefusedError";
  }
}

/**
 * Whether a playbook may send the browser to a URL.
 *
 * Host-based, and a host matches its own subdomains: `anaf.ro` admits `spv.anaf.ro`, because a
 * portal that moves its login to a subdomain has not become a different site. It never matches by
 * suffix alone — `evil-anaf.ro` does not end up admitted by `anaf.ro`.
 *
 * `"*"` admits everything, which is the point: a screenshot playbook genuinely wants any URL, and
 * saying so in the playbook makes it a decision a reader can see rather than a default nobody chose.
 *
 * Checked on redirects too, by the caller: a redirect is a navigation the page chose, and a page
 * that can redirect anywhere would make this check decorative.
 */
export function isAllowed(url: string, allow: AllowList): boolean {
  if (allow.includes("*")) {
    return true;
  }

  let host: string;

  try {
    const parsed = new URL(url);

    // Only the two schemes a browser should be driven to. `file:` would read the container's own
    // filesystem, and `data:`/`javascript:` are script delivery by another name.
    if (parsed.protocol !== "https:" && parsed.protocol !== "http:") {
      return false;
    }

    host = parsed.hostname.toLowerCase();
  } catch {
    return false;
  }

  return allow.some((entry) => {
    const allowed = entry.toLowerCase().replace(/^\./, "");

    return host === allowed || host.endsWith(`.${allowed}`);
  });
}

export function assertAllowed(url: string, allow: AllowList): void {
  if (!isAllowed(url, allow)) {
    throw new DestinationRefusedError(url);
  }
}
