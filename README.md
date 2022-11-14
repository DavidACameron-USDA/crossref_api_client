# Crossref API Client
This is a Drupal module that provides a connection class to the Crossref API.
By itself this module provides no front-end functionality. It is intended that
other projects will require this module as a dependency and use its connection
class ```\Drupal\crossref_api_client\Client``` to provide required
functionality.

Not all API methods have been implemented at this time. This project was
initially developed for a specific use case which only required the ability to
look up DOIs from the ```/works/{doi}``` endpoint. If you are interested in
using this module and would like to see other methods implemented, then feel
free to post an issue in the queue or submit a pull request.

## Available Methods for Developers
The following methods have been implemented in the ```Client``` class:
* ```worksDoi(string $doi)``` - Returns metadata for a DOI
* ```worksDoiExists(string $doi)``` - Checks whether or not a DOI exists

