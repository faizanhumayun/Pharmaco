#!/usr/bin/env bash
#
# Stops Pharmaco starting with this Mac and removes its icon. Nothing else: the
# application folder and the database — the books — are left where they are.
#
AGENTS="$HOME/Library/LaunchAgents"

for job in server scheduler; do
  launchctl unload "$AGENTS/com.pharmaco.$job.plist" 2>/dev/null
  rm -f "$AGENTS/com.pharmaco.$job.plist"
done

rm -rf "/Applications/Pharmaco.app" "$HOME/Applications/Pharmaco.app"

echo "Pharmaco no longer starts by itself."
echo "The application (~/Pharmaco) and its database were not touched."
