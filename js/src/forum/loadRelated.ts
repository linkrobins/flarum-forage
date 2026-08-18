import app from 'flarum/forum/app';

export interface RelatedDiscussion {
  id: number;
  slug: string;
  title: string;
  commentCount: number;
}

/**
 * Whether the list under a discussion is on for this forum.
 *
 * A forum with no key, a lapsed plan, a tenant still provisioning or an admin
 * who switched the panel off would answer every one of these requests with an
 * empty list, so the panel does not ask: the boolean rides along on the forum
 * payload the page already loaded, and saves a full Flarum boot per discussion
 * view. Read at render time, never in an initializer, because app.forum is
 * built after those run.
 */
export function relatedUnderDiscussion(): boolean {
  return !!app.forum?.attribute('forageRelated');
}

/** The same question for the composer, which has its own switch. */
export function relatedInComposer(): boolean {
  return !!app.forum?.attribute('forageRelatedComposer');
}

/**
 * Ask the forum for discussions like something.
 *
 * Always resolves, never rejects, and never raises an alert: this is a panel
 * nobody asked for, so a search server that is down, a plan that lapsed, or a
 * member who tripped the rate limit should all end in the same quiet nothing.
 * The default handler would put a red banner over the page instead.
 */
export default function loadRelated(params: Record<string, string | number>): Promise<RelatedDiscussion[]> {
  return app
    .request<{ data: RelatedDiscussion[] }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/linkrobins-forage/related',
      params,
      errorHandler: () => {},
    })
    .then((body) => body?.data ?? [])
    .catch(() => []);
}
