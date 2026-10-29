**The inbox list is tighter, shows files, and sorts by column.** The sender avatar is smaller so
rows can sit closer together. A conversation's files appear as chips at the end of its row, with a
card that opens beside a chip when the pointer rests on it: a picture shows a thumbnail, anything
else shows its icon, name and size, and a click opens the viewer or downloads the file. On a wide
list the sender, subject, attachments and date have headings above the rows: a click sorts by that
column and a second click reverses it. The sort menu still offers newest and oldest first. No schema
change.

**The top of an open conversation lists its files and offers to unsubscribe.** Every attachment in
the conversation is shown as a strip under the subject, once each even when a reply quotes the same
file again. A newsletter that publishes a `List-Unsubscribe` header gets an **Unsubscribe** button
in the toolbar: one click sends the request the sender asked for (RFC 8058) from this server after a
confirmation, or opens the sender's page, or starts a pre-filled message to their unsubscribe
address. No schema change.

**A sender's website icon can stand in for the initial on a row's avatar.** This server fetches
`https://<domain>/favicon.ico` itself, through the same address checks as remote images in mail, and
keeps the result for a week. A domain with no icon, and mailbox providers such as Gmail and Outlook,
keep the initial. **Migration:** set `SENDER_LOGOS=false` to switch it off. The sender's host learns
that this server exists and has received mail from them, which a tracking pixel would tell them too.
