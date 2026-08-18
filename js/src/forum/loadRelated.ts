import app from 'flarum/forum/app';

export interface RelatedDiscussion {
  id: number;
  slug: string;
  title: string;
  commentCount: number;
}

/**
 * Whether this forum can answer at all.
 *
 * A forum with no key, a lapsed plan or a tenant still provisioning would
 * answer every one of these requests with an empty list, so the panels do not
 * ask: the boolean rides along on the forum payload the page already loaded,
 * and saves a full Flarum boot per discussion view and per pause in the
 * composer. Read at render time, never in an initializer, because app.forum is
 * built after those run.
 */
export function relatedEnabled(): boolean {
  return !!app.forum?.attribute('forageRelated');
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
