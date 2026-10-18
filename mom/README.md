# Minutes Desk

Type rough meeting notes; Claude turns them into formal Minutes of Meeting (MOM):
summary, numbered agenda items, decisions, an action table (owner / due, gaps marked TBC),
next meeting and sign-off lines. It also lists points to confirm before sending.

- **Record**: live recording with an automatic, timestamped transcript (English or Arabic) when the page
  runs in a normal browser (Chrome, Edge or Safari). Pause, resume, stop, and save the audio file.
- **Import transcript**: bring in a .vtt, .srt or .txt transcript from Teams, Zoom, Google Meet or a phone
  recorder; speakers and timestamps are kept. Works everywhere, including on claude.ai.
- **Refine**: ask for changes in plain words ("make it shorter", "translate to Arabic").
- **Edit text**: edit the minutes directly in the page.
- **Export**: copy as plain text, copy with formatting for email, download Word (.doc) or Markdown.
- **Style**: formal (board/committee), standard or concise; English, Arabic or same as notes.

## Running it

- **On claude.ai** (published artifact): AI runs on your Claude account; meetings are saved privately to your account.
  claude.ai pages can't use the microphone, so record elsewhere and use **Import transcript**.
- **Standalone** (open `mom/index.html` in a browser): asks for an Anthropic API key (kept in that browser only);
  meetings are saved in browser storage. Recording works here (serve it over https,
  e.g. GitHub Pages, or open the file directly in Chrome).
