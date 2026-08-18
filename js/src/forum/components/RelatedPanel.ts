import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import Component from 'flarum/common/Component';
import loadRelated from '../loadRelated';
import relatedRow from '../relatedRow';
import RelatedModal from './RelatedModal';
import type { RelatedDiscussion } from '../loadRelated';
import type Mithril from 'mithril';

export interface RelatedPanelAttrs {
  discussionId: number;
}

/**
 * The "related discussions" list under a discussion.
 *
 * Renders nothing at all until it has something to show — no heading, no empty
 * state, no skeleton. A thread with no relatives is the normal case on a small
 * forum, and an empty box under every one of them would read as a fault.
 */
export default class RelatedPanel extends Component<RelatedPanelAttrs> {
  discussions: RelatedDiscussion[] = [];

  /** Whether the server had more than it sent. Its answer, not a guess here. */
  hasMore = false;

  /**
   * The discussion the current list belongs to.
   *
   * Belt and braces rather than load-bearing, and worth being honest about:
   * core's DiscussionPageResolver puts the canonicalized discussion id in the
   * route key, so moving to another discussion builds a new page and a new
   * panel with it, and this never differs across discussions today. It stays
   * because the id arrives as an attr, and an attr can change under a
   * component that outlives it.
   */
  loadedFor: number | null = null;

  oninit(vnode: Mithril.Vnode<RelatedPanelAttrs, this>) {
    super.oninit(vnode);

    this.load(vnode.attrs.discussionId);
  }

  onbeforeupdate(vnode: Mithril.VnodeDOM<RelatedPanelAttrs, this>) {
    super.onbeforeupdate(vnode);

    if (vnode.attrs.discussionId !== this.loadedFor) {
      this.load(vnode.attrs.discussionId);
    }

    return true;
  }

  view() {
    if (!this.discussions.length) {
      return null;
    }

    return m(
      'section',
      { className: 'ForageRelated' },
      m('h3', { className: 'ForageRelated-heading' }, app.translator.trans('linkrobins-forage.forum.related_heading')),
      m('ul', { className: 'ForageRelated-list' }, this.discussions.map(relatedRow)),
      this.more()
    );
  }

  /**
   * The rest of what the footer had to cut.
   *
   * Opens a modal rather than growing the list underneath the reader. The
   * footer's rows are a glance on the way past; asking for the rest is a
   * deliberate act, and a control that quietly reflowed the page was read as a
   * link to somewhere else.
   *
   * Shown only when the server says it cut something. Counting the rows here
   * cannot tell the difference between a thread with exactly five relatives and
   * one with fifty, so the button used to promise more and open a window
   * holding the same five, which is likeliest on the small forums where five is
   * the whole answer.
   */
  more(): Mithril.Children {
    if (!this.hasMore) {
      return null;
    }

    return m(
      Button,
      {
        className: 'Button ForageRelated-more',
        onclick: () => app.modal.show(RelatedModal, { discussionId: this.attrs.discussionId }),
      },
      app.translator.trans('linkrobins-forage.forum.related_more')
    );
  }

  load(discussionId: number) {
    // Set before the request, not after: an update mid-flight would otherwise
    // start the same load again.
    this.loadedFor = discussionId;
    this.discussions = [];
    this.hasMore = false;

    if (!discussionId) {
      return;
    }

    loadRelated({ discussion: discussionId }).then((answer) => {
      // A member who navigated on while this was in the air gets the answer for
      // the thread they left; drop it rather than render it under the new one.
      if (this.loadedFor !== discussionId) {
        return;
      }

      this.discussions = answer.discussions;
      this.hasMore = answer.hasMore;
      m.redraw();
    });
  }
}
