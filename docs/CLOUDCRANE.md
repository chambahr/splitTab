# CloudCrane app

The public phone app is hosted by CloudCrane at:

https://splittrip-cjkuwi80up.cloudcrane.ai/

CloudCrane app id: `ed2c25b0-997e-47dd-b79d-cfd01b9abfc2`

The live app has three saved versions:

1. V1: trip creation, budget, expense capture, equal allocations and settle-up view.
2. V2: expense category and backwards-compatible saved-data migration.
3. V3: local trip participants, equal/manual expense allocations, notes and backwards-compatible migration.

The app is currently a local-only prototype. The live source and rollback history are maintained by CloudCrane. The backend in this archive has not yet been deployed or connected. CloudCrane's screenshot and browser-test services returned transient upstream failures during this build, so do not interpret publication as a completed visual or browser QA sign-off.

Install on iPhone: open the link in Safari, tap Share, then Add to Home Screen.

Install on Android: open the link in Chrome and choose Install app or Add to Home screen.

A fourth refinement release was shipped after V3 to expose saved state for regression assertions and allow users to correct a manual-split validation error without restarting the form. The stored data schema remains version 3 because that release did not introduce a new persisted field shape.
