import os
import re


source = os.environ["GITHUB_REPOSITORY"]
owner = source.split("/", 1)[0]
distribution = os.environ["DISTRIBUTION_REPOSITORY"]
distribution_owner, _, name = distribution.partition("/")
# With owner set, an empty repositories input grants access to every installed repository.
if (distribution_owner.lower() != owner.lower()
    or distribution.lower() == source.lower()
    or not re.fullmatch(r"[A-Za-z0-9_.-]+", name)):
    raise SystemExit("Distribution repository must be a different repository in the source organization")
print(f"name={name}")
