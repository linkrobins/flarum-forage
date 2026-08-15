import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

interface StatusResponse {
  status: string;
  detail: string;
  configured: boolean;
  reachable: boolean;
  indexed: number | null;
  cap: number;
}

/**
 * Tells the admin whether their search key actually works.
 *
 * Same shape as the banner on Warble and Chirp: one Alert at the top of the
 * settings page, plain language, with the tick in the message rather than an
 * icon of its own. Only the three Alert styles Flarum ships are used, because
 * those are the three the rest of the family uses.
 *
 * It renders immediately from the status stored at the last save, the way those
 * two do, then replaces it with a live answer from the extension's own
 * endpoint. That is worth the extra call: the stored status cannot know whether
 * the search server is answering right now, or how much of the forum it holds.
 */
export default class ForageStatus extends Component {
  status: StatusResponse | null = null;

  retrying = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    this.load();
  }

  view() {
    const condition = this.condition();

    return m(
      'div',
      { className: this.alertClass(condition), style: 'margin-bottom:16px;' },
      this.body(condition),
      // Only the wait has anything useful to press: a key that is merely being
      // set up comes good on its own, without the admin retyping anything.
      condition === 'provisioning'
        ? m(
            'div',
            { style: 'margin-top:10px;' },
            Button.component(
              {
                className: 'Button',
                loading: this.retrying,
                onclick: () => this.retry(),
              },
              this.trans('check_again')
            )
          )
        : null
    );
  }

  /**
   * Which of the states the banner describes.
   *
   * Reachability is judged separately from the stored status, because the two
   * can disagree: a key that exchanged fine last week still says "ok" when the
   * search server is down today, and the admin needs to be told that. Running
   * out of the plan's posts is called out the same way, because search quietly
   * stops covering new posts and nothing else would say so.
   */
  condition(): string {
    const status = this.status;

    if (!status) {
      return String(app.data.settings['linkrobins-forage.status'] || 'unconfigured');
    }

    if (status.status === 'ok' && !status.reachable) {
      return 'unreachable';
    }

    if (status.status === 'ok' && status.cap > 0 && status.indexed !== null && status.indexed >= status.cap) {
      return 'at_limit';
    }

    return status.status;
  }

  alertClass(condition: string): string {
    switch (condition) {
      case 'ok':
        return 'Alert Alert--success';
      case 'invalid':
      case 'error':
      case 'unreachable':
      case 'at_limit':
        return 'Alert Alert--error';
      // Nothing pasted yet, or a key whose server is still being built: neither
      // of those is anything wrong.
      default:
        return 'Alert';
    }
  }

  body(condition: string): Mithril.Children {
    const status = this.status;

    if (condition === 'at_limit' && status) {
      return this.trans('at_limit', {
        indexed: status.indexed?.toLocaleString(),
        cap: status.cap.toLocaleString(),
      });
    }

    // The count only exists once the live answer has arrived, so until then the
    // connected message is the one without it.
    if (condition === 'ok' && status && status.indexed !== null) {
      return this.trans('connected_indexed', { indexed: status.indexed.toLocaleString() });
    }

    return this.trans(condition === 'ok' ? 'connected' : condition);
  }

  trans(key: string, params: Record<string, unknown> = {}) {
    return app.translator.trans('linkrobins-forage.admin.' + key, params);
  }

  load() {
    this.request('GET', 'status');
  }

  retry() {
    this.retrying = true;

    this.request('POST', 'retry').then(() => {
      this.retrying = false;
      m.redraw();
    });
  }

  request(method: string, path: string): Promise<void> {
    return app
      .request<StatusResponse>({
        method,
        url: app.forum.attribute('apiUrl') + '/linkrobins-forage/' + path,
      })
      .then((status) => {
        this.status = status;
        m.redraw();
      })
      .catch(() => {
        // A banner that cannot reach its own endpoint is not worth an error
        // dialog. It falls back to the status stored at the last save, and the
        // settings below it still work.
      });
  }
}
