<?php 

namespace Dazamate\SurrealGraphSync\Enum;

enum QueryType: string {
    case POST = 'post';
    case USER  = 'user';
    case TERM  = 'term';
}
