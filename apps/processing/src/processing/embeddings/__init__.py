"""Dense text embeddings for legislation retrieval (`BLUEPRINT.md` §15.2).

Runs the model **in this container**, never through a third-party API. That is
not a performance choice: `docs/STATUS.md` blocked item #3 (provider, region,
DPA) gates every external AI call this project makes, and a self-hosted model
sidesteps it entirely for this corpus — the text embedded here is public
Romanian legislation, and the search queries that reach it may not be.
"""
