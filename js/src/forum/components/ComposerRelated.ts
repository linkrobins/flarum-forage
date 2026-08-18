import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import loadRelated from '../loadRelated';
import type { RelatedDiscussion } from '../loadRelated';
import type Mithril from 'mithril';

export interface ComposerRelatedAttrs {
  /** The composer's title field. */
  title: () => string;
}

/** Long enough that it does not fire mid-word, short enough to feel immediate. */
const DEBOUNCE_MS = 400;

/** Matches MIN_QUERY_LENGTH on the server; short titles match half a forum. */
const MIN_LENGTH = 4;

/**
 * "Has this been asked before?" under the new-discussion title field.
 *
 * Advisory, always. It never disables the button, never blocks submission and
 * can be dismissed outright, because the match behind it is lexical: two
 * threads sharing a few words are often nothing to do with each other, and a
 * feature that stops people posting on that evidence gets uninstalled.
 */
export default class ComposerRelated extends Component<ComposerRelatedAttrs> {
  discussions: RelatedDiscussion[] = [];

  /** Hidden for good, by the button. A member who says no meant it. */
  dismissed = false;

  /**
   * Showing right now.
   *
   * Separate from dismissed because it floats over the editor: clicking into
   * the post, pressing Escape or clicking anywhere else has to put it away, and
   * none of those mean "never show me this again". The next title does bring it
   * back.
   */
  private open = false;

  /** The title text the current suggestions were asked for. */
  private seen = '';

  private timer: ReturnType<typeof setTimeout> | null = null;

  private detach: Array<() => void> = [];

  /**
   * The wrapper is rendered even while empty.
   *
   * A component whose view returns null has no DOM, and onupdate is what drives
   * the debounce, so an empty wrapper is what keeps this listening while there
   * is nothing yet to show. It is also what the panel is positioned against:
   * the panel floats over the editor rather than pushing it down the page, so
   * nothing moves when suggestions arrive four hundred milliseconds after
   * somebody stopped typing.
   */
  view() {
    return m('div', { className: 'ForageComposerRelated' }, this.dismissed || !this.open || !this.discussions.length ? null : this.panel());
  }

  oncreate(vnode: Mithril.VnodeDOM<ComposerRelatedAttrs, this>) {
    super.oncreate(vnode);

    // Capture phase, so a handler that stops propagation on its way up cannot
    // leave the panel floating over the editor with nothing to close it.
    const away = (e: Event) => {
      const target = e.target as HTMLElement | null;

      if (!this.open || !target) {
        return;
      }

      // Typing in the title is what asked for this, so it does not count as
      // clicking away.
      if (vnode.dom.contains(target) || target.closest('.DiscussionComposer-title')) {
        return;
      }

      this.close();
    };

    const escape = (e: KeyboardEvent) => {
      if (this.open && e.key === 'Escape') {
        this.close();
      }
    };

    document.addEventListener('pointerdown', away, true);
    document.addEventListener('keydown', escape, true);

    this.detach = [() => document.removeEventListener('pointerdown', away, true), () => document.removeEventListener('keydown', escape, true)];
  }

  onupdate(vnode: Mithril.VnodeDOM<ComposerRelatedAttrs, this>) {
    super.onupdate(vnode);

    const title = (vnode.attrs.title() || '').trim();

    if (title === this.seen) {
      return;
    }

    this.seen = title;
    this.schedule(title);
  }

  onremove(vnode: Mithril.VnodeDOM<ComposerRelatedAttrs, this>) {
    super.onremove(vnode);

    this.clear();
    this.detach.forEach((off) => off());
    this.detach = [];
  }

  panel(): Mithril.Children {
    return m(
      'div',
      { className: 'ForageComposerRelated-panel' },
      m(
        'div',
        { className: 'ForageComposerRelated-header' },
        m('span', { className: 'ForageComposerRelated-heading' }, app.translator.trans('linkrobins-forage.forum.composer_heading')),
        m(
          'button',
          {
            className: 'Button Button--link ForageComposerRelated-dismiss',
            type: 'button',
            onclick: () => {
              this.dismissed = true;
              this.open = false;
            },
          },
          app.translator.trans('linkrobins-forage.forum.composer_dismiss')
        )
      ),
      m(
        'ul',
        { className: 'ForageComposerRelated-list' },
        this.discussions.map((discussion) =>
          m(
            'li',
            null,
            m(
              Link,
              {
                className: 'ForageComposerRelated-link',
                href: app.route('discussion', { id: discussion.id + '-' + discussion.slug }),
                external: true,
                target: '_blank',
              },
              discussion.title
            )
          )
        )
      )
    );
  }

  private schedule(title: string) {
    this.clear();

    if (title.length < MIN_LENGTH) {
      this.discussions = [];

      return;
    }

    this.timer = setTimeout(() => {
      loadRelated({ q: title }).then((discussions) => {
        // Typing moved on while this was in the air.
        if (this.seen !== title) {
          return;
        }

        this.discussions = discussions;
        this.open = discussions.length > 0;
        m.redraw();
      });
    }, DEBOUNCE_MS);
  }

  private close() {
    this.open = false;
    m.redraw();
  }

  private clear() {
    if (this.timer !== null) {
      clearTimeout(this.timer);
      this.timer = null;
    }
  }
}
