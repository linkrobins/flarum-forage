import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
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
 * The status is decided on the server when the key is saved, so it cannot come
 * from the settings the page just sent. It is fetched from the extension's own
 * endpoint instead, which also lets the banner report something the settings
 * table does not know: whether the search server is answering right now, and
 * how much of the forum it holds.
 */
export default class ForageStatus extends Component {
  status: StatusResponse | null = null;
  loading = true;
  retrying = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    this.load();
  }

  view() {
    if (this.loading) {
      return m('.ForageStatus.ForageStatus--loading', [
        LoadingIndicator.component({ display: 'inline', size: 'small' }),
        ' ',
        this.trans('checking'),
      ]);
    }

    const status = this.status;

    if (!status) {
      return null;
    }

    const condition = this.condition(status);

    return m('.ForageStatus.ForageStatus--' + condition, [
      m('.ForageStatus-heading', [m('i.icon.' + this.icon(condition)), ' ', m('span', this.trans(condition + '_title'))]),
      m('.ForageStatus-body', this.body(status, condition)),
      // Only the wait has anything useful to press: a key that is merely being
      // set up becomes good on its own, without the admin retyping it.
      condition === 'provisioning'
        ? Button.component(
            {
              className: 'Button Button--primary ForageStatus-retry',
              loading: this.retrying,
              onclick: () => this.retry(),
            },
            this.trans('check_again')
          )
        : null,
    ]);
  }

  /**
   * Reachability is checked separately from the stored status, because the two
   * can disagree: a key that exchanged fine last week says "ok" even when the
   * search server is down today, and the admin needs to be told that.
   */
  condition(status: StatusResponse): string {
    if (status.status === 'ok' && !status.reachable) {
      return 'unreachable';
    }

    return status.status;
  }

  body(status: StatusResponse, condition: string): Mithril.Children {
    if (condition !== 'ok') {
      return this.trans(condition + '_body');
    }

    if (status.indexed === null) {
      return this.trans('ok_body');
    }

    const indexed = status.indexed.toLocaleString();

    if (status.cap > 0 && status.indexed >= status.cap) {
      return this.trans('ok_body_at_limit', { indexed, cap: status.cap.toLocaleString() });
    }

    return this.trans('ok_body_indexed', { indexed });
  }

  icon(condition: string): string {
    switch (condition) {
      case 'ok':
        return 'fas fa-circle-check';
      case 'provisioning':
        return 'fas fa-hourglass-half';
      case 'unconfigured':
        return 'fas fa-key';
      default:
        return 'fas fa-triangle-exclamation';
    }
  }

  trans(key: string, params: Record<string, unknown> = {}) {
    return app.translator.trans('linkrobins-forage.admin.status.' + key, params);
  }

  load() {
    app
      .request<StatusResponse>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/linkrobins-forage/status',
      })
      .then((status) => {
        this.status = status;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        // A banner that cannot load is not worth an error dialog; the settings
        // below it still work.
        this.loading = false;
        m.redraw();
      });
  }

  retry() {
    this.retrying = true;

    app
      .request<StatusResponse>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/linkrobins-forage/retry',
      })
      .then((status) => {
        this.status = status;
        this.retrying = false;
        m.redraw();
      })
      .catch(() => {
        this.retrying = false;
        m.redraw();
      });
  }
}
