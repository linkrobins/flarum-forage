import app from 'flarum/forum/app';

export interface RelatedDiscussion {
  id: number;
  slug: string;
  title: string;
  commentCount: number;
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
