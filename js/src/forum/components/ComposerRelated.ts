import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
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
 * goes away on any gesture that says so, because the match behind it is
 * lexical: two threads sharing a few words are often nothing to do with each
 * other, and a feature that stops people posting on that evidence gets
 * uninstalled.
 */
export default class ComposerRelated extends Component<ComposerRelatedAttrs> {
  discussions: RelatedDiscussion[] = [];

  /**
   * Showing right now.
   *
   * Nothing here closes it for good. It floats over the editor, so clicking
   * into the post, pressing Escape, clicking anywhere else and the dismiss
   * button all have to put it away, and none of those mean "never again":
   * closing a typeahead is not a decision, it is getting it out of the way.
   * Changing the title brings it back, and so does clicking back into the
   * title, which is the gesture a member has for asking again.
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
    return m('div', { className: 'ForageComposerRelated' }, !this.open || !this.discussions.length ? null : this.panel());
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

    // Asking again. Whatever put the panel away, going back to the field it
    // belongs to is how a member gets it back without retyping the title.
    //
    // On both a click and a focus, because either one on its own leaves a hole:
    // Escape puts the panel away without moving focus, so clicking the title
    // afterwards focuses nothing that was not focused already and fires no
    // focus event, while tabbing back into the field is a focus with no click.
    const back = (e: Event) => {
      const target = e.target as HTMLElement | null;

      if (this.open || !this.discussions.length || !target?.closest('.DiscussionComposer-title')) {
        return;
      }

      this.open = true;
      m.redraw();
    };

    document.addEventListener('pointerdown', away, true);
    document.addEventListener('pointerdown', back, true);
    document.addEventListener('keydown', escape, true);
    document.addEventListener('focusin', back, true);

    this.detach = [
      () => document.removeEventListener('pointerdown', away, true),
      () => document.removeEventListener('pointerdown', back, true),
      () => document.removeEventListener('keydown', escape, true),
      () => document.removeEventListener('focusin', back, true),
    ];
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
        m(Button, {
          className: 'Button Button--link Button--icon ForageComposerRelated-dismiss',
          icon: 'fas fa-times',
          // Icon only, so the name of the thing lives in the label rather than
          // beside it. Core's own dismissals are shaped the same way.
          'aria-label': app.translator.trans('linkrobins-forage.forum.composer_dismiss'),
          title: app.translator.trans('linkrobins-forage.forum.composer_dismiss'),
          onclick: () => this.close(),
        })
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
